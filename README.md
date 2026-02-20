# MCIROCHAT-PIERODEV

Microchat tipo “mini ChatGPT” hecho en **PHP** y conectado a **Ollama (IA local)** mediante `http://localhost:11434/api/generate`.

El proyecto está pensado para publicarlo en GitHub como una versión “final” simple, clara y funcional, con controles básicos y utilidades de producto.

**Autor:** Piero Olivares (PieroDev)

---

## Qué incluye

- Chat **multi-turn** (historial real) guardado en sesión (`$_SESSION["chat"]`).
- Controles de respuesta:
  - Selector de **modelo**
  - Selector de **estilo**: `breve / normal / detallado`
  - Control de **temperature** (creatividad)
- Utilidades:
  - **Guardar ajustes**
  - **Vaciar chat**
  - **Exportar conversación a JSON**
  - Botón **Copiar** en cada respuesta del asistente (portapapeles)

---

## Estructura del repositorio

```txt
MCIROCHAT-PIERODEV/
├─ microchat-pierodev.php
└─ microchat_conversacion.json
````

* `microchat-pierodev.php`: aplicación completa (front + lógica + llamada a Ollama).
* `microchat_conversacion.json`: ejemplo de archivo exportado por la función “Exportar JSON”.

---

## Requisitos

* **PHP** (recomendado con XAMPP en Windows).
* Extensión **cURL** habilitada en PHP.
* **Ollama** instalado y ejecutándose en local.

---

## Cómo ejecutar (Windows + XAMPP)

1. Asegura que Ollama está corriendo (por defecto usa el puerto 11434).

2. Coloca el proyecto dentro de tu servidor local, por ejemplo:

```txt
C:\xampp\htdocs\MCIROCHAT-PIERODEV\
```

3. Abre en el navegador:

```txt
http://localhost/MCIROCHAT-PIERODEV/microchat-pierodev.php
```

---

## Configuración rápida (dentro del archivo PHP)

En `microchat-pierodev.php` puedes modificar:

* Endpoint de Ollama:

```php
$OLLAMA_URL = "http://localhost:11434/api/generate";
```

* Modelos del selector:

```php
$MODELS = [
  "llama3.1:8b-instruct-q4_K_M" => "llama3.1:8b-instruct-q4_K_M",
  "phi3:mini" => "phi3:mini",
  ...
];
```

* Prompt base del sistema:

```php
$SYSTEM_PROMPT = <<<TXT
...
TXT;
```

* Límites de contexto e historial:

```php
$MAX_CONTEXT_MSGS = 12;
$MAX_SAVED_MSGS   = 80;
```

---

## Cómo funciona (resumen técnico)

* El chat se guarda en `$_SESSION["chat"]` con elementos del tipo:

  * `role`: `user` o `assistant`
  * `content`: texto
  * `ts`: timestamp

* Para cada envío:

  1. Se agrega el mensaje del usuario al historial.
  2. Se construye un `prompt` con:

     * `SYSTEM_PROMPT`
     * instrucción del estilo (breve/normal/detallado)
     * últimos `MAX_CONTEXT_MSGS` mensajes
  3. Se llama a Ollama con `stream=false` para recibir una respuesta completa.
  4. Se guarda la respuesta del asistente en el historial.

* El botón **Copiar** está implementado en JavaScript, copiando el texto de la burbuja del asistente al portapapeles (con fallback si `clipboard` no está disponible).

---

## Exportar conversación

El botón **Exportar JSON** descarga un archivo con:

* `cfg`: configuración actual (modelo, estilo, temperature)
* `chat`: historial completo
* `exported_at`: fecha ISO del export

El archivo `microchat_conversacion.json` del repo es un ejemplo real de ese export.

---

## Problemas comunes

* “ERROR cURL”:

  * Verifica que la extensión `curl` esté habilitada en tu `php.ini`.
* “HTTP 404/500” o “revisa modelo/endpoint”:

  * Confirma que Ollama está activo en `localhost:11434`.
  * Confirma que el modelo existe y coincide con el nombre en `ollama list`.

---

## Licencia

Añade la licencia que prefieras (por ejemplo MIT) si vas a publicarlo como proyecto abierto.

---

## Autor

Piero Olivares (PieroDev)

