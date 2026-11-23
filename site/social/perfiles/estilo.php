<?php
/**
 * social/perfiles/estilo.php?id=<style> — Thumbnail of an AI style of Profile Studio (generated once by an admin, profile_ai_lib.php).
 * Anyone with Social Studio sees them (they are examples, not anyone's photos).
 */
require_once __DIR__ . '/../../auth.php';
requireLogin();
require_once dirname(__DIR__) . '/lib.php';
$id = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['id'] ?? ''));
$p = ss_files_dir() . 'pfstyles/' . $id . '.jpg';
if ($id === '' || !is_file($p)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=604800');
readfile($p);
