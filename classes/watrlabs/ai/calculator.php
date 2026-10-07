<?php

namespace watrlabs\ai;

// tiny math parser for the calculator tool. never eval() what a model sends you.
// supports + - * / % ^, parentheses, pi, e and a handful of functions
class calculator {

    private array $tokens = [];
    private int $pos = 0;

    private const FUNCTIONS = [
        "sqrt"=>1, "abs"=>1, "sin"=>1, "cos"=>1, "tan"=>1, "asin"=>1, "acos"=>1, "atan"=>1,
        "log"=>1, "ln"=>1, "exp"=>1, "floor"=>1, "ceil"=>1, "round"=>[1, 2], "min"=>[1, 20], "max"=>[1, 20], "pow"=>2,
    ];

    static function evaluate(string $expression): float {
        $calc = new self();
        $calc->tokens = self::tokenize($expression);
        $calc->pos = 0;

        $result = $calc->expression();

        if($calc->pos < count($calc->tokens)){
            throw new \InvalidArgumentException("Unexpected '" . $calc->tokens[$calc->pos][1] . "'");
        }

        if(is_nan($result) || is_infinite($result)){
            throw new \InvalidArgumentException("The result isn't a finite number");
        }

        return $result;
    }

    static function format(float $value): string {
        if(floor($value) == $value && abs($value) < 1e15){
            return number_format($value, 0, ".", "");
        }

        return rtrim(rtrim(sprintf("%.12g", $value), "0"), ".");
    }

    private static function tokenize(string $input): array {
        $tokens = [];
        $input = str_replace(["×", "÷", "**"], ["*", "/", "^"], $input);
        $length = strlen($input);
        $i = 0;

        while($i < $length){
            $char = $input[$i];

            if(ctype_space($char) || $char === ","){
                if($char === ","){
                    $tokens[] = ["sep", ","];
                }
                $i++;
                continue;
            }

            if(ctype_digit($char) || $char === "."){
                if(!preg_match('/\G(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $input, $m, 0, $i)){
                    throw new \InvalidArgumentException("Bad number");
                }
                $tokens[] = ["num", (float) $m[0]];
                $i += strlen($m[0]);
                continue;
            }

            if(ctype_alpha($char)){
                preg_match('/\G[a-zA-Z]+/', $input, $m, 0, $i);
                $tokens[] = ["name", strtolower($m[0])];
                $i += strlen($m[0]);
                continue;
            }

            if(strpos("+-*/%^()", $char) !== false){
                $tokens[] = ["op", $char];
                $i++;
                continue;
            }

            throw new \InvalidArgumentException("Unexpected character '$char'");
        }

        if(count($tokens) > 500){
            throw new \InvalidArgumentException("Expression is too long");
        }

        return $tokens;
    }

    private function peek(){
        return $this->tokens[$this->pos] ?? null;
    }

    private function accept(string $type, $value = null){
        $token = $this->peek();
        if($token && $token[0] === $type && ($value === null || $token[1] === $value)){
            $this->pos++;
            return $token;
        }
        return null;
    }

    private function expect(string $type, $value){
        if(!$this->accept($type, $value)){
            throw new \InvalidArgumentException("Expected '$value'");
        }
    }

    // expression := term (("+" | "-") term)*
    private function expression(): float {
        $value = $this->term();

        while(true){
            if($this->accept("op", "+")){
                $value += $this->term();
            } elseif($this->accept("op", "-")){
                $value -= $this->term();
            } else {
                return $value;
            }
        }
    }

    // term := unary (("*" | "/" | "%") unary)*
    private function term(): float {
        $value = $this->unary();

        while(true){
            if($this->accept("op", "*")){
                $value *= $this->unary();
            } elseif($this->accept("op", "/")){
                $divisor = $this->unary();
                if($divisor == 0){
                    throw new \InvalidArgumentException("Division by zero");
                }
                $value /= $divisor;
            } elseif($this->accept("op", "%")){
                $divisor = $this->unary();
                if($divisor == 0){
                    throw new \InvalidArgumentException("Division by zero");
                }
                $value = fmod($value, $divisor);
            } else {
                return $value;
            }
        }
    }

    // unary := ("-" | "+") unary | power      so -2^2 is -(2^2) like in normal math
    private function unary(): float {
        if($this->accept("op", "-")){
            return -$this->unary();
        }
        if($this->accept("op", "+")){
            return $this->unary();
        }
        return $this->power();
    }

    // power := primary ("^" unary)?   (right associative, 2^3^2 = 2^9)
    private function power(): float {
        $base = $this->primary();

        if($this->accept("op", "^")){
            return pow($base, $this->unary());
        }

        return $base;
    }

    private function primary(): float {
        if($token = $this->accept("num")){
            return $token[1];
        }

        if($this->accept("op", "(")){
            $value = $this->expression();
            $this->expect("op", ")");
            return $value;
        }

        if($token = $this->accept("name")){
            $name = $token[1];

            if($name === "pi"){
                return M_PI;
            }
            if($name === "e"){
                return M_E;
            }

            if(!isset(self::FUNCTIONS[$name])){
                throw new \InvalidArgumentException("Unknown name '$name'");
            }

            $this->expect("op", "(");
            $args = [$this->expression()];
            while($this->accept("sep", ",")){
                $args[] = $this->expression();
            }
            $this->expect("op", ")");

            return $this->call($name, $args);
        }

        throw new \InvalidArgumentException("Expression ended early");
    }

    private function call(string $name, array $args): float {
        $arity = self::FUNCTIONS[$name];
        [$min, $max] = is_array($arity) ? $arity : [$arity, $arity];

        if(count($args) < $min || count($args) > $max){
            throw new \InvalidArgumentException("Wrong number of arguments for $name()");
        }

        switch($name){
            case "sqrt":
                if($args[0] < 0){
                    throw new \InvalidArgumentException("Square root of a negative number");
                }
                return sqrt($args[0]);
            case "abs": return abs($args[0]);
            case "sin": return sin($args[0]);
            case "cos": return cos($args[0]);
            case "tan": return tan($args[0]);
            case "asin": return asin($args[0]);
            case "acos": return acos($args[0]);
            case "atan": return atan($args[0]);
            case "log":
            case "ln":
                if($args[0] <= 0){
                    throw new \InvalidArgumentException("Logarithm of a non-positive number");
                }
                return $name === "log" ? log10($args[0]) : log($args[0]);
            case "exp": return exp($args[0]);
            case "floor": return floor($args[0]);
            case "ceil": return ceil($args[0]);
            case "round": return round($args[0], (int) ($args[1] ?? 0));
            case "min": return min($args);
            case "max": return max($args);
            case "pow": return pow($args[0], $args[1]);
        }

        throw new \InvalidArgumentException("Unknown function $name");
    }
}
