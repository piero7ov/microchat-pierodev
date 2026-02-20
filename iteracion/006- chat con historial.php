<?php
/** * ---------------------------------------------------------
 * OBJETIVO:
 * - Microchat UI clara
 * - Historial real multi-turn con $_SESSION["chat"]
 * - Contexto: se construye el prompt con los últimos mensajes
 * - Controles: modelo + estilo + temperature (sin tokens)
 * - Botones utilidad:
 *    - Vaciar chat
 *    - Exportar conversación (JSON)
 */

session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

/* =========================
   Config
   ========================= */
$OLLAMA_URL = "http://localhost:11434/api/generate";

/* Tus modelos reales */
$MODELS = [
  "llama3.1:8b-instruct-q4_K_M" => "llama3.1:8b-instruct-q4_K_M",
  "phi3:mini"                   => "phi3:mini",
  "mistral:instruct"            => "mistral:instruct",
  "deepseek-r1:latest"          => "deepseek-r1:latest",
  "qwen2.5-coder:7b"            => "qwen2.5-coder:7b",
  "llama3:latest"               => "llama3:latest",
];

/* Prompt base */
$SYSTEM_PROMPT = <<<TXT
Eres un asistente general útil. Responde en español claro.
- Si falta información, pregunta 1 cosa concreta.
- Usa listas solo si ayudan.
- No inventes datos: si no sabes, dilo.
TXT;

/* Cuántos mensajes usar para contexto (multi-turn) */
$MAX_CONTEXT_MSGS = 12;
/* Límite total guardado en sesión (para que no crezca infinito) */
$MAX_SAVED_MSGS   = 80;

/* =========================
   Defaults (cfg + chat en sesión)
   ========================= */
if (!isset($_SESSION["cfg"])) {
  $_SESSION["cfg"] = [
    "model" => "qwen2.5-coder:7b",
    "style" => "normal",
    "temp"  => 0.6,
  ];
}
if (!isset($_SESSION["chat"]) || !is_array($_SESSION["chat"])) {
  $_SESSION["chat"] = [];
}

/* Normaliza modelo */
if (!isset($MODELS[$_SESSION["cfg"]["model"]])) {
  $_SESSION["cfg"]["model"] = array_key_first($MODELS);
}

/* =========================
   Helpers
   ========================= */
function styleInstruction($style){
  $style = strtolower(trim((string)$style));
  if ($style === "breve") return "Responde MUY breve: 1-3 frases, directo, sin rodeos.";
  if ($style === "detallado") return "Responde detallado: explica paso a paso, con ejemplos si ayuda.";
  return "Responde normal: claro y útil, sin extenderte demasiado.";
}

function pushChatMessage($role, $content){
  if (!isset($_SESSION["chat"]) || !is_array($_SESSION["chat"])) $_SESSION["chat"] = [];
  $_SESSION["chat"][] = [
    "role" => (string)$role,            // user | assistant
    "content" => (string)$content,
    "ts" => time(),
  ];

  /* recorte por seguridad */
  if (count($_SESSION["chat"]) > 80) {
    $_SESSION["chat"] = array_slice($_SESSION["chat"], -80);
  }
}

function buildPromptFromHistory($systemPrompt, $styleLine, $history, $maxMsgs){
  $msgs = $history;
  if (count($msgs) > $maxMsgs) {
    $msgs = array_slice($msgs, -$maxMsgs);
  }

  $out  = "INSTRUCCIONES:\n" . $systemPrompt . "\n";
  $out .= "- " . $styleLine . "\n\n";
  $out .= "CONVERSACIÓN (contexto):\n";

  foreach ($msgs as $m) {
    $role = (string)($m["role"] ?? "");
    $txt  = (string)($m["content"] ?? "");
    if ($role === "user") {
      $out .= "Tú: " . $txt . "\n";
    } elseif ($role === "assistant") {
      $out .= "Asistente: " . $txt . "\n";
    }
  }

  $out .= "\nAsistente:\n";
  return $out;
}

/* =========================
   Estado
   ========================= */
$error = "";

/* =========================
   Acciones POST
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $action = (string)($_POST["action"] ?? "");

  /* 1) Exportar JSON */
  if ($action === "export") {
    $export = [
      "cfg" => $_SESSION["cfg"],
      "chat" => $_SESSION["chat"],
      "exported_at" => date("c"),
    ];
    header("Content-Type: application/json; charset=UTF-8");
    header('Content-Disposition: attachment; filename="microchat_conversacion.json"');
    echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
  }

  /* 2) Vaciar chat */
  if ($action === "clear_chat") {
    $_SESSION["chat"] = [];
  }

  /* 3) Guardar ajustes (modelo/estilo/temp) */
  $model = (string)($_POST["model"] ?? $_SESSION["cfg"]["model"]);
  $style = (string)($_POST["style"] ?? $_SESSION["cfg"]["style"]);
  $temp  = (float)($_POST["temp"] ?? $_SESSION["cfg"]["temp"]);

  if (!isset($MODELS[$model])) $model = $_SESSION["cfg"]["model"];
  $style = strtolower($style);
  if (!in_array($style, ["breve","normal","detallado"], true)) $style = $_SESSION["cfg"]["style"];
  if ($temp < 0.0) $temp = 0.0;
  if ($temp > 1.5) $temp = 1.5;

  $_SESSION["cfg"]["model"] = $model;
  $_SESSION["cfg"]["style"] = $style;
  $_SESSION["cfg"]["temp"]  = $temp;

  /* 4) Enviar mensaje */
  if ($action === "send") {
    $msg = trim((string)($_POST["msg"] ?? ""));

    if ($msg === "") {
      $error = "Escribe un mensaje.";
    } elseif (mb_strlen($msg, "UTF-8") > 3000) {
      $error = "Mensaje demasiado largo (máx 3000 caracteres).";
    } else {
      /* Guardar mensaje del usuario en historial */
      pushChatMessage("user", $msg);

      /* Construir prompt con contexto */
      $styleLine = styleInstruction($style);
      $promptFinal = buildPromptFromHistory($SYSTEM_PROMPT, $styleLine, $_SESSION["chat"], $MAX_CONTEXT_MSGS);

      /* Llamada a Ollama */
      $payload = [
        "model"       => $model,
        "prompt"      => $promptFinal,
        "stream"      => false,
        "temperature" => $temp,
      ];

      $ch = curl_init($OLLAMA_URL);
      curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 200,
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
            $answer = trim((string)($data["response"] ?? ""));
            if ($answer === "") {
              $error = "Llegó respuesta vacía (revisa modelo).";
            } else {
              /* Guardar respuesta IA en historial */
              pushChatMessage("assistant", $answer);
            }
          }
        }
      }
    }
  }
}

/* Valores actuales para UI */
$currentModel = $_SESSION["cfg"]["model"];
$currentStyle = $_SESSION["cfg"]["style"];
$currentTemp  = (float)$_SESSION["cfg"]["temp"];
$chat         = $_SESSION["chat"];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Microchat Historial</title>
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
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
      align-items:flex-start;
    }
    .title{ font-weight:800; font-size:18px; line-height:1.1; }
    .sub{ margin-top:6px; color:var(--muted); font-size:13px; display:flex; gap:10px; flex-wrap:wrap; }
    .pill{ border:1px solid var(--border); background:#fafafa; padding:4px 10px; border-radius:999px; }

    .panel{
      margin-top:14px;
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:14px 16px;
      display:flex;
      gap:14px;
      flex-wrap:wrap;
      align-items:flex-end;
    }
    .field{ display:flex; flex-direction:column; gap:6px; min-width:220px; }
    label{ font-size:13px; color:var(--muted); font-weight:700; }
    select, input[type="range"]{
      border:1px solid var(--border);
      border-radius:12px;
      padding:10px 12px;
      background:#fff;
      font-size:14px;
      outline:none;
    }
    select:focus, input[type="range"]:focus{ border-color:#bcd0ff; box-shadow:0 0 0 3px rgba(59,130,246,.12); }
    .tempRow{ display:flex; gap:10px; align-items:center; }
    .tempVal{
      min-width:48px;
      text-align:center;
      border:1px solid var(--border);
      background:#fafafa;
      padding:8px 10px;
      border-radius:12px;
      font-size:14px;
      color:var(--text);
    }

    .tools{
      display:flex;
      gap:8px;
      margin-left:auto;
      flex-wrap:wrap;
    }
    button{
      border:1px solid var(--border);
      border-radius:12px;
      padding:10px 12px;
      background:#fff;
      cursor:pointer;
      font-size:14px;
    }
    button.primary{
      background:var(--btn);
      color:var(--btnText);
      border-color:var(--btn);
    }
    button.primary:hover{ filter:brightness(0.95); }
    button.secondary:hover{ background:#f3f4f6; }

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
      overflow:auto;
    }
    .bubble{
      max-width:84%;
      padding:12px 14px;
      border-radius:14px;
      border:1px solid var(--border);
      line-height:1.35;
      white-space: normal;
    }
    .who{ font-size:12px; color:var(--muted); margin-bottom:6px; font-weight:700; }
    .msg{ white-space: pre-wrap; margin:0; }

    .user{ align-self:flex-end; background:var(--userBg); border-color:var(--userBorder); }
    .assistant{ align-self:flex-start; background:var(--asBg); border-color:var(--asBorder); }

    .empty{
      color:var(--muted);
      font-size:14px;
      padding:10px 0;
    }

    .err{
      margin-top:12px;
      background:#fff1f2;
      border:1px solid #fecdd3;
      color:#9f1239;
      padding:10px 12px;
      border-radius:12px;
    }

    form.send{
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
      min-width:170px;
    }

    .hint{ color:var(--muted); font-size:13px; margin-top:8px; }

    @media (max-width:720px){
      .field{ min-width:160px; flex:1; }
      .right{ min-width:140px; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div>
        <div class="title">Microchat | PieroDev</div>
        <div class="sub">
          <span class="pill">Endpoint: <?=h($OLLAMA_URL)?></span>
          <span class="pill">Multi-turn</span>
          <span class="pill">Contexto: últimos <?=h((string)$MAX_CONTEXT_MSGS)?> mensajes</span>
        </div>
      </div>
    </header>

    <!-- Controles + utilidades -->
    <form class="panel" method="post">
      <div class="field">
        <label for="model">Modelo</label>
        <select id="model" name="model">
          <?php foreach($MODELS as $k => $label): ?>
            <option value="<?=h($k)?>" <?=($k===$currentModel ? "selected" : "")?>><?=h($label)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="style">Estilo</label>
        <select id="style" name="style">
          <option value="breve" <?=($currentStyle==="breve" ? "selected" : "")?>>Breve</option>
          <option value="normal" <?=($currentStyle==="normal" ? "selected" : "")?>>Normal</option>
          <option value="detallado" <?=($currentStyle==="detallado" ? "selected" : "")?>>Detallado</option>
        </select>
      </div>

      <div class="field" style="min-width:280px;">
        <label for="temp">Temperature</label>
        <div class="tempRow">
          <input id="temp" name="temp" type="range" min="0" max="1.5" step="0.1" value="<?=h((string)$currentTemp)?>">
          <div class="tempVal"><?=h(number_format($currentTemp, 1))?></div>
        </div>
      </div>

      <div class="tools">
        <button class="secondary" type="submit" name="action" value="save">Guardar ajustes</button>
        <button class="secondary" type="submit" name="action" value="clear_chat">Vaciar chat</button>
        <button class="secondary" type="submit" name="action" value="export">Exportar JSON</button>
      </div>

      <!-- Para no perder el texto si guardas ajustes -->
      <input type="hidden" name="msg" value="">
    </form>

    <!-- Chat (historial) -->
    <div class="chat" id="chatBox">
      <?php if (empty($chat)): ?>
        <div class="bubble assistant">
          <div class="who">Asistente</div>
          <div class="msg">Hola 👋 preguntame algo.</div>
        </div>
      <?php else: ?>
        <?php foreach($chat as $m): ?>
          <?php
            $role = (string)($m["role"] ?? "");
            $txt  = (string)($m["content"] ?? "");
            $cls  = ($role === "user") ? "user" : "assistant";
            $who  = ($role === "user") ? "Tú" : "Asistente";
          ?>
          <div class="bubble <?=h($cls)?>">
            <div class="who"><?=h($who)?></div>
            <div class="msg"><?=h($txt)?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php if ($error !== ""): ?>
      <div class="err"><?=h($error)?></div>
    <?php endif; ?>

    <!-- Enviar mensaje -->
    <form class="send" method="post">
      <div style="flex:1;">
        <textarea name="msg" placeholder="Escribe tu mensaje..."></textarea>
      </div>

      <!-- mantener cfg -->
      <input type="hidden" name="model" value="<?=h($currentModel)?>">
      <input type="hidden" name="style" value="<?=h($currentStyle)?>">
      <input type="hidden" name="temp"  value="<?=h((string)$currentTemp)?>">

      <div class="right">
        <button class="primary" type="submit" name="action" value="send">Enviar</button>
        <button class="secondary" type="submit" name="action" value="clear_chat">Vaciar chat</button>
      </div>
    </form>
  </div>

  <script>
    (function(){
      const r = document.getElementById("temp");
      const v = document.querySelector(".tempVal");
      if(r && v){
        r.addEventListener("input", () => v.textContent = Number(r.value).toFixed(1));
      }
      // Auto-scroll al final del chat
      const box = document.getElementById("chatBox");
      if(box) box.scrollTop = box.scrollHeight;
    })();
  </script>
</body>
</html>