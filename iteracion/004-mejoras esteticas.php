<?php
/**
 * - Prompt base (sistema) para IA general
 * - Single-turn (1 pregunta + 1 respuesta)
 */

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

/* =========================
   Config
   ========================= */
$OLLAMA_URL = "http://localhost:11434/api/generate";
$MODEL      = "qwen2.5-coder:7b"; // cambia por tu modelo real si es distinto

$SYSTEM_PROMPT = <<<TXT
Eres un asistente general útil. Responde en español claro.
- Si falta información, pregunta 1 cosa concreta.
- Usa listas solo si ayudan.
- No inventes datos: si no sabes, dilo.
TXT;

/* =========================
   Estado
   ========================= */
$userMsg   = "";
$assistant = "";
$error     = "";

/* =========================
   POST -> llamar a Ollama
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $userMsg = trim((string)($_POST["msg"] ?? ""));

  if ($userMsg === "") {
    $error = "Escribe un mensaje.";
  } elseif (mb_strlen($userMsg, "UTF-8") > 3000) {
    $error = "Mensaje demasiado largo (máx 3000 caracteres).";
  } else {

    $promptFinal =
      "INSTRUCCIONES:\n" . $SYSTEM_PROMPT . "\n\n" .
      "USUARIO:\n" . $userMsg . "\n\n" .
      "ASISTENTE:\n";

    $payload = [
      "model"  => $MODEL,
      "prompt" => $promptFinal,
      "stream" => false,
    ];

    $ch = curl_init($OLLAMA_URL);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
      CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
      CURLOPT_TIMEOUT        => 180,
    ]);

    $raw = curl_exec($ch);

    if ($raw === false) {
      $error = "ERROR cURL: " . curl_error($ch);
      curl_close($ch);
    } else {
      $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($http < 200 || $http >= 300) {
        $error = "HTTP $http (revisa modelo/endpoint).";
      } else {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
          $error = "No se pudo parsear JSON. Respuesta cruda: " . $raw;
        } else {
          $assistant = trim((string)($data["response"] ?? ""));
          if ($assistant === "") $error = "Llegó respuesta vacía (revisa modelo).";
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
  <title>Microchat | PieroDev</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      --bg:#f6f7fb;
      --card:#ffffff;
      --text:#111827;
      --muted:#6b7280;
      --border:#e5e7eb;
      --shadow:0 10px 24px rgba(17,24,39,.08);
      --radius:14px;

      --userBg:#e8f0ff;
      --userBorder:#cfe0ff;

      --asBg:#ecfdf5;
      --asBorder:#b7f7d8;

      --btn:#111827;
      --btnText:#ffffff;
      --btn2:#ffffff;
      --btn2Text:#111827;
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background:var(--bg);
      color:var(--text);
      padding:20px;
    }
    .wrap{ max-width:980px; margin:auto; }

    header{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:14px 16px;
      display:flex;
      gap:12px;
      align-items:flex-start;
      justify-content:space-between;
    }
    .title{
      font-weight:800;
      font-size:18px;
      margin:0;
      line-height:1.1;
    }
    .sub{
      margin-top:6px;
      color:var(--muted);
      font-size:13px;
      display:flex;
      gap:10px;
      flex-wrap:wrap;
    }
    .pill{
      border:1px solid var(--border);
      background:#fafafa;
      padding:4px 10px;
      border-radius:999px;
    }

    .chat{
      margin-top:14px;
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:16px;
      min-height:360px;
      display:flex;
      flex-direction:column;
      gap:12px;
    }

    .bubble{
      max-width:84%;
      padding:12px 14px;
      border-radius:14px;
      border:1px solid var(--border);
      line-height:1.35;
      white-space: normal;
    }
    .who{
      font-size:12px;
      color:var(--muted);
      margin-bottom:6px;
      font-weight:700;
    }

    .msg{
      white-space: pre-wrap;
      margin:0;
    }

    .user{
      align-self:flex-end;
      background:var(--userBg);
      border-color:var(--userBorder);
    }
    .assistant{
      align-self:flex-start;
      background:var(--asBg);
      border-color:var(--asBorder);
    }

    .err{
      margin-top:12px;
      background:#fff1f2;
      border:1px solid #fecdd3;
      color:#9f1239;
      padding:10px 12px;
      border-radius:12px;
    }

    form{
      margin-top:14px;
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:14px;
      display:flex;
      gap:10px;
      align-items:flex-end;
    }

    textarea{
      width:100%;
      min-height:84px;
      max-height:240px;
      resize:vertical;
      padding:12px;
      font-size:16px;
      border-radius:12px;
      border:1px solid var(--border);
      background:#ffffff;
      color:var(--text);
      outline:none;
    }
    textarea:focus{ border-color:#bcd0ff; box-shadow:0 0 0 3px rgba(59,130,246,.12); }

    .right{
      display:flex;
      flex-direction:column;
      gap:8px;
      min-width:160px;
    }

    button, a.btn{
      display:inline-block;
      width:100%;
      text-align:center;
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--border);
      text-decoration:none;
      cursor:pointer;
      font-size:14px;
    }
    button{
      background:var(--btn);
      color:var(--btnText);
      border-color:var(--btn);
    }
    button:hover{ filter:brightness(0.95); }

    a.btn{
      background:var(--btn2);
      color:var(--btn2Text);
    }
    a.btn:hover{ background:#f3f4f6; }

    .hint{ color:var(--muted); font-size:13px; margin-top:8px; }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div>
        <div class="title">Microchat | PieroDev</div>
        <div class="sub">
          <span class="pill">Modelo: <?=h($MODEL)?></span>
          <span class="pill">Endpoint: <?=h($OLLAMA_URL)?></span>
          <span class="pill">Single-turn</span>
        </div>
      </div>
    </header>

    <div class="chat">
      <?php if ($userMsg === "" && $assistant === ""): ?>
        <div class="bubble assistant">
          <div class="who">Asistente</div>
          <div class="msg">Hola 👋 Escribe un mensaje abajo y te respondo con tu IA local (Ollama).</div>
        </div>
      <?php endif; ?>

      <?php if ($userMsg !== ""): ?>
        <div class="bubble user">
          <div class="who">Tú</div>
          <div class="msg"><?=h($userMsg)?></div>
        </div>
      <?php endif; ?>

      <?php if ($assistant !== ""): ?>
        <div class="bubble assistant">
          <div class="who">Asistente</div>
          <div class="msg"><?=h($assistant)?></div>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($error !== ""): ?>
      <div class="err"><?=h($error)?></div>
    <?php endif; ?>

    <form method="post">
      <div style="flex:1;">
        <textarea name="msg" placeholder="Escribe tu mensaje..."><?=h($userMsg)?></textarea>
      </div>
      <div class="right">
        <button type="submit">Enviar</button>
        <a class="btn" href="<?=h($_SERVER["PHP_SELF"])?>">Limpiar</a>
      </div>
    </form>
  </div>
</body>
</html>