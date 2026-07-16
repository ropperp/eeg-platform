FROM node:20-slim

# TeX Live mit deutschen Sprachpaketen + eurosym
# cm-super wird explizit ergänzt: es ist bei Debian/Ubuntu nur ein "Recommends" von
# texlive-fonts-extra, das --no-install-recommends deshalb NICHT mitinstalliert. Ohne
# cm-super (skalierbare Type1-Fonts) bricht microtype (in allen .tex-Vorlagen verwendet)
# mit "pdfTeX error (font expansion): auto expansion is only possible with scalable
# fonts" fatal ab — PDF-Generierung schlägt dann komplett fehl.
RUN apt-get update && apt-get install -y --no-install-recommends \
    texlive-latex-base \
    texlive-latex-recommended \
    texlive-latex-extra \
    texlive-lang-german \
    texlive-fonts-recommended \
    texlive-fonts-extra \
    cm-super \
    lmodern \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json .
RUN npm install --omit=dev
COPY service.js .
# templates-default bleibt unangetastet im Image (Rückfallebene) -- ./templates selbst wird
# im Produktivbetrieb durch das persistente Volume /opt/eeg/latex-templates überdeckt (siehe
# docker-compose.yml + entrypoint.sh), damit Platform-Admin-Uploads einen --build überleben.
COPY templates/ ./templates-default/
COPY templates/ ./templates/
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh && mkdir -p /tmp/latex-jobs

EXPOSE 3210
ENTRYPOINT ["/entrypoint.sh"]
CMD ["node", "service.js"]
