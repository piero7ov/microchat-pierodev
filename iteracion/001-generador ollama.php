<?php
/**
 * ---------------------------------------------------------
 * - Probar desde PHP una llamada directa a Ollama (local)
 * - Endpoint: http://localhost:11434/api/generate
 * - Imprime la respuesta en bruto (texto)
 */

/* =========================
   Config
   ========================= */
$OLLAMA_URL = "http://localhost:11434/api/generate";
$MODEL      = "qwen2.5-coder:7b";

/* Prompt de prueba:
   - Puedes pasarlo por GET: ?q=hola
   - Si no, usa uno fijo */
$q = isset($_GET["q"]) ? trim((string)$_GET["q"]) : "";
$prompt = ($q !== "") ? $q : "Hola, responde en español en una frase.";

/* Payload básico para /api/generate */
$payload = [
  "model"  => $MODEL,
  "prompt" => $prompt,
  "stream" => false,   // importante: respuesta completa en una sola vez
];

/* =========================
   Llamada por cURL
   ========================= */
$ch = curl_init($OLLAMA_URL);
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT        => 60,
]);

$response = curl_exec($ch);

if ($response === false) {
  $err = curl_error($ch);
  curl_close($ch);
  header("Content-Type: text/plain; charset=UTF-8");
  echo "ERROR cURL: $err\n";
  echo "¿Ollama está corriendo? ¿El puerto 11434 está disponible?\n";
  exit;
}

$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header("Content-Type: text/plain; charset=UTF-8");

if ($httpCode < 200 || $httpCode >= 300) {
  echo "HTTP $httpCode\n";
  echo "Respuesta cruda:\n$response\n";
  exit;
}

/* =========================
   Parse JSON y salida
   ========================= */
$data = json_decode($response, true);

if (!is_array($data)) {
  echo "No se pudo parsear JSON.\n";
  echo "Respuesta cruda:\n$response\n";
  exit;
}

/* En /api/generate normalmente viene 'response' */
$text = (string)($data["response"] ?? "");

echo "PROMPT:\n$prompt\n\n";
echo "RESPUESTA:\n$text\n";