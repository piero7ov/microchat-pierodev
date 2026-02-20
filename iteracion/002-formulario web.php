<?php
/**
 * - Tener un formulario web (POST) para enviar un prompt
 * - Llamar a Ollama (/api/generate) desde PHP
 * - Mostrar la respuesta en la misma página
 *
 * Sigue siendo simple. Todavía NO hay historial multi-turn.
 */

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

/* =========================
   Config
   ========================= */
$OLLAMA_URL = "http://localhost:11434/api/generate";
$MODEL      = "qwen2.5-coder:7b"; // cambia si tu modelo se llama distinto

/* =========================
   Estado
   ========================= */
$prompt   = "";
$respuesta = "";
$error    = "";

/* =========================
   POST -> llamar a Ollama
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $prompt = trim((string)($_POST["prompt"] ?? ""));

  if ($prompt === "") {
    $error = "Escribe algo en el prompt.";
  } else {
    $payload = [
      "model"  => $MODEL,
      "prompt" => $prompt,
      "stream" => false,
    ];

    $ch = curl_init($OLLAMA_URL);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
      CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
      CURLOPT_TIMEOUT        => 60,
    ]);

    $raw = curl_exec($ch);

    if ($raw === false) {
      $error = "ERROR cURL: " . curl_error($ch);
      curl_close($ch);
    } else {
      $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($http < 200 || $http >= 300) {
        $error = "HTTP $http (modelo mal o Ollama no responde).";
      } else {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
          $error = "No se pudo parsear JSON. Respuesta cruda: " . $raw;
        } else {
          $respuesta = (string)($data["response"] ?? "");
          if ($respuesta === "") {
            $error = "Llegó respuesta vacía (revisa modelo/endpoint).";
          }
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>003 - Ollama (Form Web)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{ font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:20px; }
    .wrap{ max-width:900px; margin:auto; }
    textarea{ width:100%; min-height:120px; padding:12px; font-size:16px; }
    button{ padding:10px 14px; font-size:16px; cursor:pointer; }
    .box{ background:#f6f6f6; border:1px solid #ddd; padding:12px; margin-top:14px; white-space:pre-wrap; }
    .err{ background:#ffe8e8; border:1px solid #ffb0b0; color:#8a0000; padding:10px; margin-top:14px; }
    .meta{ color:#666; font-size:14px; margin:8px 0 0; }
    label{ font-weight:600; display:block; margin-bottom:6px; }
    .row{ display:flex; gap:10px; align-items:center; margin-top:10px; }
    .pill{ font-size:13px; background:#eee; padding:4px 8px; border-radius:999px; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>003 - Ollama (Formulario Web)</h1>
    <div class="meta">
      Modelo: <span class="pill"><?=h($MODEL)?></span>
      Endpoint: <span class="pill"><?=h($OLLAMA_URL)?></span>
    </div>

    <form method="post">
      <label for="prompt">Prompt</label>
      <textarea id="prompt" name="prompt" placeholder="Escribe tu pregunta..."><?=h($prompt)?></textarea>
      <div class="row">
        <button type="submit">Enviar</button>
        <a href="<?=h($_SERVER["PHP_SELF"])?>">Limpiar</a>
      </div>
    </form>

    <?php if ($error !== ""): ?>
      <div class="err"><?=h($error)?></div>
    <?php endif; ?>

    <?php if ($respuesta !== ""): ?>
      <h2>Respuesta</h2>
      <div class="box"><?=h($respuesta)?></div>
    <?php endif; ?>
  </div>
</body>
</html>