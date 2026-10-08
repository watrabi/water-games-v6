# the box the AI runs code in (see runner.py for the limits it runs under)
FROM python:3.13-slim

RUN apt-get update \
 && apt-get install -y --no-install-recommends curl git jq unzip zip ffmpeg imagemagick \
 && rm -rf /var/lib/apt/lists/*

RUN pip install --no-cache-dir numpy pandas matplotlib pillow requests beautifulsoup4 sympy scipy openpyxl

# never root. /work is the chat's own folder, mounted in at run time
RUN useradd --uid 10001 --home-dir /work --no-create-home sandbox
ENV HOME=/work PYTHONUSERBASE=/work/.local PATH=/work/.local/bin:$PATH MPLBACKEND=Agg PIP_DISABLE_PIP_VERSION_CHECK=1
WORKDIR /work
USER sandbox
