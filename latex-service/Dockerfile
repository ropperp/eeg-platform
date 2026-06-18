FROM node:20-slim

# TeX Live mit deutschen Sprachpaketen + eurosym
RUN apt-get update && apt-get install -y --no-install-recommends \
    texlive-latex-base \
    texlive-latex-recommended \
    texlive-latex-extra \
    texlive-lang-german \
    texlive-fonts-recommended \
    texlive-fonts-extra \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json .
RUN npm install --omit=dev
COPY service.js .
COPY templates/ ./templates/

RUN mkdir -p /tmp/latex-jobs

EXPOSE 3210
CMD ["node", "service.js"]
