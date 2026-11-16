# EDA-Worker: verarbeitet EDA-Importjobs (worker.py) mit den bestehenden Parsern.
# Version + Digest fest (reproduzierbare Builds); Updates schlägt Dependabot vor (.github/dependabot.yml).
FROM python:3.12.16-slim@sha256:5e2bdb1b0d542d5039cffb8181c65930093949194a65c365dfb1b0fe13a4fa5b

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY parser.py parser_interval.py worker.py ./

ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
CMD ["python", "worker.py"]
