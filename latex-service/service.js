'use strict';

const express = require('express');
const { execFile } = require('child_process');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const os = require('os');

const app = express();
app.use(express.json({ limit: '1mb' }));

const API_KEY      = process.env.API_KEY || 'dev-key';
const TEMPLATE_DIR = path.join(__dirname, 'templates');
const TMP_DIR      = path.join(os.tmpdir(), 'latex-jobs');
fs.mkdirSync(TMP_DIR, { recursive: true });

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
  const { template, vars = {} } = req.body;

  if (!template || !/^[a-z_]+$/.test(template)) {
    return res.status(400).json({ error: 'Invalid template name' });
  }

  const tplFile = path.join(TEMPLATE_DIR, template + '.tex');
  if (!fs.existsSync(tplFile)) {
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

  run((err1) => {
    if (err1 && !fs.existsSync(pdfPath)) {
      console.error('[latex] First pass error:', err1.message, '\n', readLog());
      cleanup(jobDir);
      return res.status(500).json({ error: 'pdflatex failed' });
    }
    run((err2) => {
      if (!fs.existsSync(pdfPath)) {
        console.error('[latex] Second pass — no PDF\n', readLog());
        cleanup(jobDir);
        return res.status(500).json({ error: 'pdflatex produced no output' });
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
