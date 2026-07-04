<?php

/** @var SuperExport\Web\View $view */
/** @var string $title */

use SuperExport\Web\View;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= View::e($title) ?> — SuperExport</title>
<style>
:root { --bg:#f6f7f9; --card:#fff; --ink:#1c2430; --muted:#66707d; --accent:#2563eb; --border:#dde2e8; --ok:#15803d; --err:#b91c1c; }
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.5 system-ui, "Segoe UI", Roboto, sans-serif; }
.wrap { max-width: 960px; margin: 0 auto; padding: 24px 16px 64px; }
nav { display:flex; gap:4px; align-items:center; padding:12px 16px; background:var(--card); border-bottom:1px solid var(--border); }
nav .brand { font-weight:700; margin-right:16px; }
nav a { color:var(--ink); text-decoration:none; padding:6px 12px; border-radius:6px; }
nav a:hover { background:var(--bg); }
h1 { font-size:22px; margin:20px 0 12px; }
h2 { font-size:17px; margin:24px 0 8px; }
table { width:100%; border-collapse:collapse; background:var(--card); border:1px solid var(--border); border-radius:8px; overflow:hidden; }
th, td { text-align:left; padding:8px 12px; border-bottom:1px solid var(--border); vertical-align:top; }
th { background:#eef1f5; font-weight:600; }
tr:last-child td { border-bottom:none; }
.card { background:var(--card); border:1px solid var(--border); border-radius:8px; padding:16px 20px; margin:12px 0; }
.muted { color:var(--muted); }
.ok { color:var(--ok); }
.err { color:var(--err); }
.btn { display:inline-block; background:var(--accent); color:#fff; border:none; border-radius:6px; padding:9px 18px; font-size:15px; cursor:pointer; text-decoration:none; }
.btn:hover { filter:brightness(1.08); }
.btn.secondary { background:#e5e9ef; color:var(--ink); }
input[type=text], select { width:100%; max-width:480px; padding:7px 10px; border:1px solid var(--border); border-radius:6px; font:inherit; background:#fff; }
label { display:block; margin:12px 0 4px; font-weight:600; }
label.inline { display:inline-flex; gap:6px; align-items:center; font-weight:400; margin:8px 16px 8px 0; }
pre.log { background:#0f172a; color:#d8e1ef; padding:14px 16px; border-radius:8px; max-height:420px; overflow:auto; font:13px/1.5 ui-monospace, Consolas, monospace; white-space:pre-wrap; }
code { background:#eef1f5; padding:1px 5px; border-radius:4px; font-size:13px; }
.badge { display:inline-block; background:#eef1f5; border-radius:999px; padding:2px 10px; font-size:13px; margin:0 4px 4px 0; }
.badge.ok-badge { background:#dcfce7; color:var(--ok); }
.detect-list { list-style:none; margin:12px 0 0; padding:0; }
.detect-list li { padding:6px 0; border-bottom:1px solid var(--border); }
.detect-list > li { padding:10px 0; }
.detect-sub { list-style:none; margin:6px 0 0; padding:0 0 0 28px; }
.detect-sub li { padding:3px 0; border-bottom:none; }
.detect-sub-0 .detect-item span { font-weight:600; }
.detect-sub-1 .detect-item span { color:var(--muted); font-size:14px; }
.detect-detail { margin:2px 0 4px 28px; font-size:12px; color:var(--err); word-break:break-word; }
.detect-list li:last-child { border-bottom:none; }
.detect-list li.detect-selected { background:#f0fdf4; margin:0 -20px; padding:6px 20px; border-radius:6px; }
.detect-item { display:flex; align-items:center; gap:10px; margin:0; font-weight:400; cursor:default; }
.detect-item input[type=checkbox] { width:16px; height:16px; margin:0; accent-color:var(--ok); }
.detect-item input[type=checkbox]:not(:checked) { opacity:.45; }
h3 { font-size:15px; margin:20px 0 8px; }
.actions { margin-top:16px; }
input.map { max-width:260px; }
</style>
</head>
<body>
<nav>
  <span class="brand">SuperExport</span>
  <a href="<?= View::e($view->url('dashboard')) ?>">Dashboard</a>
  <a href="<?= View::e($view->url('export')) ?>">Export</a>
  <a href="<?= View::e($view->url('import')) ?>">Import</a>
</nav>
<div class="wrap">
