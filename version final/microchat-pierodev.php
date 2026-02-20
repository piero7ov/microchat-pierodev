<?php
/**
 * -------------------------------------------------------
 * Microchat tipo "mini ChatGPT" (IA local con Ollama) en PHP.
 *
 * Características:
 * - Historial multi-turn usando sesión: $_SESSION["chat"]
 * - Controles de "producto": modelo, estilo (breve/normal/detallado), temperature
 * - Utilidades: guardar ajustes, vaciar chat, exportar historial a JSON
 * - Botón por burbuja del asistente para copiar respuesta al portapapeles
 *
 * Nota:
 * - Se usa /api/generate de Ollama con stream=false (respuesta completa)
 * - El contexto se construye como texto plano (últimos N mensajes)
 */

session_start();

/**
 * Escape HTML seguro para imprimir contenido controlado por usuario/IA
 * y evitar inyección HTML (XSS).
 */
function h($s){
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

/* =========================================================
   Configuración
   ========================================================= */

/**
 * Endpoint local de Ollama (API de generación).
 * Debe estar activo en localhost:11434.
 */
$OLLAMA_URL = "http://localhost:11434/api/generate";

/**
 * Lista de modelos disponibles (según "ollama list").
 * Se usa para poblar el selector y validar la selección del usuario.
 */
$MODELS = [
  "llama3.1:8b-instruct-q4_K_M" => "llama3.1:8b-instruct-q4_K_M",
  "phi3:mini"                   => "phi3:mini",
  "mistral:instruct"            => "mistral:instruct",
  "deepseek-r1:latest"          => "deepseek-r1:latest",
  "qwen2.5-coder:7b"            => "qwen2.5-coder:7b",
  "llama3:latest"               => "llama3:latest",
];

/**
 * Prompt de sistema: reglas globales del asistente.
 * Se inyecta al inicio del prompt final.
 */
$SYSTEM_PROMPT = <<<TXT
Eres un asistente general útil. Responde en español claro.
- Si falta información, pregunta 1 cosa concreta.
- Usa listas solo si ayudan.
- No inventes datos: si no sabes, dilo.
TXT;

/**
 * Máximo de mensajes usados como contexto al construir el prompt.
 * Esto evita que el prompt crezca sin control y reduzca rendimiento.
 */
$MAX_CONTEXT_MSGS = 12;

/**
 * Máximo de mensajes guardados en sesión.
 * Se recorta el historial cuando supera este número.
 */
$MAX_SAVED_MSGS   = 80;

/* =========================================================
   Inicialización de estado persistente (sesión)
   ========================================================= */

/**
 * Configuración persistente del chat.
 * Se guarda en $_SESSION["cfg"] para mantener el estado entre requests.
 */
if (!isset($_SESSION["cfg"])) {
  $_SESSION["cfg"] = [
    "model" => "qwen2.5-coder:7b", // modelo por defecto
    "style" => "normal",           // breve | normal | detallado
    "temp"  => 0.6,                // temperature por defecto
  ];
}

/**
 * Historial de conversación.
 * Cada elemento es un array con:
 * - role: "user" o "assistant"
 * - content: texto
 * - ts: timestamp unix
 */
if (!isset($_SESSION["chat"]) || !is_array($_SESSION["chat"])) {
  $_SESSION["chat"] = [];
}

/**
 * Si por alguna razón el modelo guardado ya no existe en la lista,
 * se reemplaza por el primer modelo disponible.
 */
if (!isset($MODELS[$_SESSION["cfg"]["model"]])) {
  $_SESSION["cfg"]["model"] = array_key_first($MODELS);
}

/* =========================================================
   Funciones auxiliares (lógica)
   ========================================================= */

/**
 * Devuelve una instrucción adicional según el estilo elegido.
 * Esta línea se añade al prompt final para guiar la forma de respuesta.
 *
 * @param string $style  "breve" | "normal" | "detallado"
 * @return string
 */
function styleInstruction($style){
  $style = strtolower(trim((string)$style));

  if ($style === "breve") {
    return "Responde MUY breve: 1-3 frases, directo, sin rodeos.";
  }
  if ($style === "detallado") {
    return "Responde detallado: explica paso a paso, con ejemplos si ayuda.";
  }
  return "Responde normal: claro y útil, sin extenderte demasiado.";
}

/**
 * Inserta un mensaje en el historial guardado en sesión y aplica recorte.
 *
 * @param string $role    "user" o "assistant"
 * @param string $content texto del mensaje
 * @return void
 */
function pushChatMessage($role, $content){
  global $MAX_SAVED_MSGS;

  if (!isset($_SESSION["chat"]) || !is_array($_SESSION["chat"])) {
    $_SESSION["chat"] = [];
  }

  $_SESSION["chat"][] = [
    "role"    => (string)$role,
    "content" => (string)$content,
    "ts"      => time(),
  ];

  /**
   * Recorte del historial para evitar crecimiento infinito.
   * Se conservan los últimos $MAX_SAVED_MSGS mensajes.
   */
  if (count($_SESSION["chat"]) > $MAX_SAVED_MSGS) {
    $_SESSION["chat"] = array_slice($_SESSION["chat"], -$MAX_SAVED_MSGS);
  }
}

/**
 * Construye el prompt final a partir del prompt de sistema + estilo + historial.
 * Se convierte el historial en texto plano, etiquetando cada turno:
 * - "Tú:" para role=user
 * - "Asistente:" para role=assistant
 *
 * @param string $systemPrompt
 * @param string $styleLine
 * @param array  $history
 * @param int    $maxMsgs
 * @return string
 */
function buildPromptFromHistory($systemPrompt, $styleLine, $history, $maxMsgs){
  $msgs = $history;

  /**
   * Limitar el contexto a los últimos N mensajes para control de tamaño.
   */
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

  /**
   * Marcador final indicando que ahora debe responder el asistente.
   * Esto ayuda a que el modelo continúe en el rol correcto.
   */
  $out .= "\nAsistente:\n";
  return $out;
}

/* =========================================================
   Estado de UI
   ========================================================= */

$error = "";

/* =========================================================
   Manejo de POST (acciones del usuario)
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $action = (string)($_POST["action"] ?? "");

  /* -------------------------
     Exportar historial a JSON
     ------------------------- */
  if ($action === "export") {
    $export = [
      "cfg"         => $_SESSION["cfg"],
      "chat"        => $_SESSION["chat"],
      "exported_at" => date("c"),
    ];

    header("Content-Type: application/json; charset=UTF-8");
    header('Content-Disposition: attachment; filename="microchat_conversacion.json"');
    echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
  }

  /* -------------------------
     Vaciar historial
     ------------------------- */
  if ($action === "clear_chat") {
    $_SESSION["chat"] = [];
  }

  /* -------------------------
     Guardar ajustes (modelo/estilo/temp)
     ------------------------- */
  $model = (string)($_POST["model"] ?? $_SESSION["cfg"]["model"]);
  $style = (string)($_POST["style"] ?? $_SESSION["cfg"]["style"]);
  $temp  = (float)($_POST["temp"]  ?? $_SESSION["cfg"]["temp"]);

  /**
   * Validación del modelo: solo permitir modelos existentes en $MODELS.
   * Si el usuario manipula el form, se vuelve al valor guardado.
   */
  if (!isset($MODELS[$model])) {
    $model = $_SESSION["cfg"]["model"];
  }

  /**
   * Validación de estilo: únicamente valores permitidos.
   */
  $style = strtolower($style);
  if (!in_array($style, ["breve","normal","detallado"], true)) {
    $style = $_SESSION["cfg"]["style"];
  }

  /**
   * Rango acotado para temperature.
   */
  if ($temp < 0.0) $temp = 0.0;
  if ($temp > 1.5) $temp = 1.5;

  /**
   * Persistencia de la configuración.
   */
  $_SESSION["cfg"]["model"] = $model;
  $_SESSION["cfg"]["style"] = $style;
  $_SESSION["cfg"]["temp"]  = $temp;

  /* -------------------------
     Enviar mensaje (llamar a Ollama)
     ------------------------- */
  if ($action === "send") {
    $msg = trim((string)($_POST["msg"] ?? ""));

    /**
     * Validaciones básicas de entrada:
     * - No permitir vacío
     * - Limitar longitud para evitar prompts enormes y problemas de rendimiento
     */
    if ($msg === "") {
      $error = "Escribe un mensaje.";
    } elseif (mb_strlen($msg, "UTF-8") > 3000) {
      $error = "Mensaje demasiado largo (máx 3000 caracteres).";
    } else {
      /**
       * Guardar el turno del usuario en historial antes de generar respuesta.
       */
      pushChatMessage("user", $msg);

      /**
       * Construir el prompt final con contexto.
       */
      $styleLine   = styleInstruction($style);
      $promptFinal = buildPromptFromHistory($SYSTEM_PROMPT, $styleLine, $_SESSION["chat"], $MAX_CONTEXT_MSGS);

      /**
       * Payload para Ollama (/api/generate):
       * - model: modelo seleccionado
       * - prompt: texto completo (sistema + conversación + marcador final)
       * - stream: false para recibir la respuesta completa en un solo JSON
       * - temperature: parámetro de creatividad
       */
      $payload = [
        "model"       => $model,
        "prompt"      => $promptFinal,
        "stream"      => false,
        "temperature" => $temp,
      ];

      /**
       * Llamada HTTP con cURL.
       */
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

        /**
         * Validación de estado HTTP.
         */
        if ($http < 200 || $http >= 300) {
          $error = "HTTP $http (revisa modelo/endpoint).";
        } else {
          /**
           * Parseo del JSON de respuesta de Ollama.
           * Para /api/generate con stream=false se espera "response".
           */
          $data = json_decode($raw, true);

          if (!is_array($data)) {
            $error = "No se pudo parsear JSON. Respuesta cruda: " . $raw;
          } else {
            $answer = trim((string)($data["response"] ?? ""));

            if ($answer === "") {
              $error = "Llegó respuesta vacía (revisa modelo).";
            } else {
              /**
               * Guardar la respuesta del asistente en el historial.
               */
              pushChatMessage("assistant", $answer);
            }
          }
        }
      }
    }
  }
}

/* =========================================================
   Datos para render de UI (valores actuales)
   ========================================================= */
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

    .tools{ display:flex; gap:8px; margin-left:auto; flex-wrap:wrap; }
    button{
      border:1px solid var(--border);
      border-radius:12px;
      padding:10px 12px;
      background:#fff;
      cursor:pointer;
      font-size:14px;
    }
    button:hover{ background:#f3f4f6; }
    button.primary{
      background:var(--btn);
      color:var(--btnText);
      border-color:var(--btn);
    }
    button.primary:hover{ filter:brightness(0.95); }

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
      position:relative;
    }
    .who{
      font-size:12px;
      color:var(--muted);
      margin-bottom:6px;
      font-weight:700;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
    }
    .msg{ white-space: pre-wrap; margin:0; }

    .user{ align-self:flex-end; background:var(--userBg); border-color:var(--userBorder); }
    .assistant{ align-self:flex-start; background:var(--asBg); border-color:var(--asBorder); }

    /* Botón copiar: solo aparece en mensajes del asistente */
    .copyBtn{
      border:1px solid var(--border);
      background:#fff;
      padding:6px 10px;
      border-radius:999px;
      font-size:12px;
      cursor:pointer;
      line-height:1;
      user-select:none;
    }
    .copyBtn:hover{ background:#f3f4f6; }

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

    @media (max-width:720px){
      .field{ min-width:160px; flex:1; }
      .right{ min-width:140px; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div class="title">Microchat | PieroDev</div>
      <div class="sub">
        <span class="pill">Contexto: últimos <?=h((string)$MAX_CONTEXT_MSGS)?> mensajes</span>
      </div>
    </header>

    <!-- Panel de controles y utilidades -->
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
        <button type="submit" name="action" value="save">Guardar ajustes</button>
        <button type="submit" name="action" value="clear_chat">Vaciar chat</button>
        <button type="submit" name="action" value="export">Exportar JSON</button>
      </div>

      <!-- Input oculto para compatibilidad; no se usa para enviar mensaje -->
      <input type="hidden" name="msg" value="">
    </form>

    <!-- Render del historial -->
    <div class="chat" id="chatBox">
      <?php if (empty($chat)): ?>
        <div class="bubble assistant">
          <div class="who">
            <span>Asistente</span>
            <!-- Mensaje inicial no depende de data-copy real, no se copia -->
            <button type="button" class="copyBtn" data-copy="">Copiar</button>
          </div>
          <div class="msg" data-msg="1">Hola, en qué te puedo ayudar.</div>
        </div>
      <?php else: ?>
        <?php foreach($chat as $idx => $m): ?>
          <?php
            $role = (string)($m["role"] ?? "");
            $txt  = (string)($m["content"] ?? "");
            $cls  = ($role === "user") ? "user" : "assistant";
            $who  = ($role === "user") ? "Tú" : "Asistente";
            $isAssistant = ($role === "assistant");
          ?>
          <div class="bubble <?=h($cls)?>">
            <div class="who">
              <span><?=h($who)?></span>
              <?php if ($isAssistant): ?>
                <!-- data-copy contiene el índice del mensaje para localizar el texto a copiar -->
                <button type="button" class="copyBtn" data-copy="<?=h((string)$idx)?>">Copiar</button>
              <?php endif; ?>
            </div>
            <!-- data-msg usa el mismo índice para poder seleccionar el texto desde JS -->
            <div class="msg" data-msg="<?=h((string)$idx)?>"><?=h($txt)?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php if ($error !== ""): ?>
      <div class="err"><?=h($error)?></div>
    <?php endif; ?>

    <!-- Formulario de envío -->
    <form class="send" method="post">
      <div style="flex:1;">
        <textarea name="msg" placeholder="Escribe tu mensaje..."></textarea>
      </div>

      <!-- Mantener la config actual al enviar -->
      <input type="hidden" name="model" value="<?=h($currentModel)?>">
      <input type="hidden" name="style" value="<?=h($currentStyle)?>">
      <input type="hidden" name="temp"  value="<?=h((string)$currentTemp)?>">

      <div class="right">
        <button class="primary" type="submit" name="action" value="send">Enviar</button>
        <button type="submit" name="action" value="clear_chat">Vaciar chat</button>
      </div>
    </form>
  </div>

  <script>
    (function(){
      /*
        Este script hace tres cosas:
        1) Actualiza en vivo el valor mostrado de la barra de temperature.
        2) Hace auto-scroll al final del historial cuando se recarga la página.
        3) Implementa el botón "Copiar" en cada burbuja del asistente.
      */

      // 1) Sincronización UI de range (temperature)
      const r = document.getElementById("temp");
      const v = document.querySelector(".tempVal");
      if(r && v){
        r.addEventListener("input", () => v.textContent = Number(r.value).toFixed(1));
      }

      // 2) Auto-scroll al final del chat (para ver el último mensaje)
      const box = document.getElementById("chatBox");
      if(box) box.scrollTop = box.scrollHeight;

      /*
        Copiar texto al portapapeles:
        - Si el entorno es "seguro" (https o localhost con soporte), se usa navigator.clipboard.
        - Si no, se usa un fallback con textarea + document.execCommand("copy").
      */
      async function copyText(text){
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(text);
          return;
        }
        const ta = document.createElement("textarea");
        ta.value = text;
        ta.style.position = "fixed";
        ta.style.left = "-9999px";
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        document.execCommand("copy");
        document.body.removeChild(ta);
      }

      /*
        Delegación de eventos:
        - En lugar de añadir listeners a cada botón, se escucha un único click en document.
        - Se busca el botón con clase .copyBtn más cercano al objetivo.
      */
      document.addEventListener("click", async (e) => {
        const btn = e.target.closest(".copyBtn");
        if(!btn) return;

        /*
          data-copy contiene el índice del mensaje.
          Con ese índice se busca el elemento .msg con data-msg igual.
        */
        const key = btn.getAttribute("data-copy");
        const msgEl = document.querySelector('.msg[data-msg="'+ key +'"]');
        const text = msgEl ? msgEl.innerText : "";

        // Si no hay texto válido, no se intenta copiar.
        if(!text.trim()){
          btn.textContent = "Nada";
          setTimeout(() => btn.textContent = "Copiar", 900);
          return;
        }

        // Feedback visual simple: deshabilitar mientras copia.
        const prev = btn.textContent;
        btn.textContent = "Copiando...";
        btn.disabled = true;

        try{
          await copyText(text);
          btn.textContent = "Copiado";
          setTimeout(() => {
            btn.textContent = "Copiar";
            btn.disabled = false;
          }, 1200);
        }catch(err){
          btn.textContent = "Error";
          setTimeout(() => {
            btn.textContent = prev || "Copiar";
            btn.disabled = false;
          }, 1200);
        }
      });
    })();
  </script>
</body>
</html>