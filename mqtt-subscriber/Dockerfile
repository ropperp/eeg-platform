# Version + Digest fest (reproduzierbare Builds); Updates schlägt Dependabot vor (.github/dependabot.yml).
FROM python:3.15.2-slim@sha256:65a65fc7b30b06cb6df4930fd4650697cb23f4c2698ab81fc87e4b031bc36928
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY main.py measurement_buffer.py ./
CMD ["python", "main.py"]
