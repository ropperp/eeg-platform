'use strict';

const express = require('express');
const { execFile } = require('child_process');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const os = require('os');

const app = express();
app.use(express.json({ limit: '1mb' }));

const API_KEY        = process.env.API_KEY || 'dev-key';
const TEMPLATE_DIR    = path.join(__dirname, 'templates');          // persistentes Volume (Admin-Uploads)
const TEMPLATE_DEFAULT_DIR = path.join(__dirname, 'templates-default'); // im Image, Rückfallebene
const TMP_DIR      = path.join(os.tmpdir(), 'latex-jobs');
fs.mkdirSync(TMP_DIR, { recursive: true });

/**
 * Pfad zur wirksamen Vorlage: das persistente Volume (Admin-Upload über /admin/templates) hat
 * Vorrang, sonst die im Image mitgelieferte Standard-Fassung -- exakt dasselbe Zwei-Ebenen-Muster
 * wie webapp/public/index.php's adminFilePath() für Logo/Hero-Banner.
 *
 * Vorfall 04.10.2026: entrypoint.sh hat früher beim ALLERERSTEN Start (leeres Volume) ALLE
 * Standard-Vorlagen pauschal einmalig ins Volume kopiert -- jede Vorlage galt danach für immer
 * als "im Volume vorhanden" und wurde dadurch bei jedem künftigen `git pull && docker compose up
 * -d --build` komplett ignoriert, selbst wenn niemand sie je über /admin/templates angefasst
 * hatte. Patrick bekam dadurch mehrfach gemergte Vorlagen-Fixes (u.a. die Unterschrift-
 * Positionierung) nie auf dem Server zu sehen, ohne jeden Hinweis auf den Grund -- sichtbar erst,
 * als ein inzwischen im PHP-Code neu eingeführtes LaTeX-Makro (`\floatsig`) auf der stehen
 * gebliebenen alten Vorlage naturgemäß unbekannt war und LaTeX dessen Argumente ersatzweise als
 * Klartext ausgab ("3.25cm2pt" direkt über der Unterschrift). Fix: Vorlagen werden nicht mehr
 * blind ins Volume kopiert (siehe entrypoint.sh) -- stattdessen entscheidet diese Funktion live
 * bei jeder PDF-Erzeugung, welche Fassung gilt. Admin-Uploads (die tatsächlich existieren)
 * greifen dadurch weiterhin wie bisher, nie angefasste Vorlagen folgen ab sofort automatisch
 * jedem Deploy.
 */
function resolveTemplatePath(template) {
  const live = path.join(TEMPLATE_DIR, template + '.tex');
  if (fs.existsSync(live)) return live;
  const fallback = path.join(TEMPLATE_DEFAULT_DIR, template + '.tex');
  return fs.existsSync(fallback) ? fallback : null;
}

// ─── LaTeX special-char escaping ───────────────────────────────────────────
function escapeTex(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/\\/g, '\\textbackslash{}')
    .replace(/&/g,  '\\&')
    .replace(/%/g,  '\\%')
    .replace(/\$/g, '\\$')
    .replace(/#/g,  '\\#')
    .replace(/_/g,  '\\_')
    .replace(/\{/g, '\\{')
    .replace(/\}/g, '\\}')
    .replace(/~/g,  '\\textasciitilde{}')
    .replace(/\^/g, '\\textasciicircum{}')
    .replace(/—/g,  '--')
    .replace(/–/g,  '--')
    .replace(/„/g,  '\\glqq{}')
    .replace(/"/g,  '\\grqq{}')
    .replace(/«/g,  '\\glqq{}')
    .replace(/»/g,  '\\grqq{}');
}

// ─── Auth middleware ────────────────────────────────────────────────────────
function requireApiKey(req, res, next) {
  const key = req.headers['x-api-key'] || req.query.key;
  if (key !== API_KEY) return res.status(401).json({ error: 'Unauthorized' });
  next();
}

// ─── Health ────────────────────────────────────────────────────────────────
app.get('/health', (_req, res) => res.send('OK'));

// ─── Generate PDF ──────────────────────────────────────────────────────────
// POST /generate
// Body: { template: "rechnung", vars: { EEG_NAME: "...", ... } }
// Returns: PDF binary (application/pdf)
app.post('/generate', requireApiKey, (req, res) => {
  const { template, vars = {}, assets = {} } = req.body;

  if (!template || !/^[a-z_]+$/.test(template)) {
    return res.status(400).json({ error: 'Invalid template name' });
  }
  for (const filename of Object.keys(assets)) {
    if (!/^[a-z0-9_-]+\.(png|jpg|jpeg)$/i.test(filename)) {
      return res.status(400).json({ error: `Invalid asset filename '${filename}'` });
    }
  }

  const tplFile = resolveTemplatePath(template);
  if (!tplFile) {
    return res.status(404).json({ error: `Template '${template}' not found` });
  }

  let tex = fs.readFileSync(tplFile, 'utf8');

  // Replace all <<<VAR>>> placeholders — escape all values for LaTeX
  for (const [key, value] of Object.entries(vars)) {
    const placeholder = new RegExp(`<<<${key}>>>`, 'g');
    // Some vars are already formatted LaTeX (marked with __RAW__ prefix) — pass through
    const escaped = String(key).startsWith('RAW_')
      ? String(value)
      : escapeTex(value);
    tex = tex.replace(placeholder, escaped);
  }

  // Any remaining <<<...>>> → replace with empty string
  tex = tex.replace(/<<<[A-Z_]+>>>/g, '');

  // Write to temp dir
  const jobId = crypto.randomBytes(8).toString('hex');
  const jobDir = path.join(TMP_DIR, jobId);
  fs.mkdirSync(jobDir, { recursive: true });

  const texPath = path.join(jobDir, 'doc.tex');
  const pdfPath = path.join(jobDir, 'doc.pdf');

  fs.writeFileSync(texPath, tex, 'utf8');

  for (const [filename, content] of Object.entries(assets)) {
    const base64 = String(content).replace(/^data:image\/\w+;base64,/, '');
    fs.writeFileSync(path.join(jobDir, filename), Buffer.from(base64, 'base64'));
  }

  // Run pdflatex twice (for correct page refs)
  const run = (cb) => execFile(
    'pdflatex',
    ['-interaction=nonstopmode', '-output-directory', jobDir, texPath],
    { timeout: 30000 },
    cb
  );

  const readLog = () => {
    try { return fs.readFileSync(path.join(jobDir, 'doc.log'), 'utf8').slice(-4000); } catch { return '(no log)'; }
  };

  // LaTeX meldet Fatal Errors mit Zeilen, die mit "!" beginnen — die ersten paar
  // davon sind meist aussagekräftiger als "pdflatex failed" und ersparen einen
  // Server-Login zur Diagnose.
  const extractLatexErrors = (log) => {
    const lines = log.split('\n').filter((l) => l.startsWith('!'));
    return lines.slice(0, 3).join(' / ') || null;
  };

  run((err1) => {
    if (err1 && !fs.existsSync(pdfPath)) {
      const log = readLog();
      console.error('[latex] First pass error:', err1.message, '\n', log);
      cleanup(jobDir);
      return res.status(500).json({ error: 'pdflatex failed' + (extractLatexErrors(log) ? ': ' + extractLatexErrors(log) : '') });
    }
    run((err2) => {
      if (!fs.existsSync(pdfPath)) {
        const log = readLog();
        console.error('[latex] Second pass — no PDF\n', log);
        cleanup(jobDir);
        return res.status(500).json({ error: 'pdflatex produced no output' + (extractLatexErrors(log) ? ': ' + extractLatexErrors(log) : '') });
      }
      const pdf = fs.readFileSync(pdfPath);
      cleanup(jobDir);
      res.setHeader('Content-Type', 'application/pdf');
      res.setHeader('Content-Disposition', `attachment; filename="${template}.pdf"`);
      res.send(pdf);
    });
  });
});

function cleanup(dir) {
  try { fs.rmSync(dir, { recursive: true, force: true }); } catch {}
}

const PORT = process.env.PORT || 3210;
app.listen(PORT, () => console.log(`latex-service listening on :${PORT}`));
