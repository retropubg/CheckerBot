<?php
// Obtener el token del bot de Telegram desde las variables de entorno
$token = getenv('TELEGRAM_BOT_TOKEN');
if (empty($token)) {
    die("❌ Error: No se encontró el token del bot.");
}

// Obtener las credenciales de la base de datos PostgreSQL desde las variables de entorno
$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$user = getenv('DB_USER');
$password = getenv('DB_PASS');
$database = getenv('DB_NAME');

// Conectar a la base de datos PostgreSQL
$connectionString = "host=$host port=$port dbname=$database user=$user password=$password";
$conn = pg_connect($connectionString);

if (!$conn) {
    die("❌ Error al conectar a la base de datos: " . pg_last_error());
}

// Obtener el contenido del mensaje recibido desde Telegram
$update = file_get_contents("php://input");
$update = json_decode($update, true);

// Verificar si el mensaje es válido
if (isset($update['message'])) {
    $chatId = $update['message']['chat']['id'];  // Capturamos el ID de chat del usuario
    $messageText = $update['message']['text'];   // Capturamos el texto del mensaje enviado por el usuario
    $username = $update['message']['chat']['username'];  // Capturamos el username del usuario

    try {
        // Solo el administrador puede usar estos comandos
        $adminId = 1292171163;

        // Comando /start
        if ($messageText === '/start') {
            $response = "¡Bienvenido! Soy tu bot. Aquí están los comandos disponibles:\n";
            $response .= "/genkey [cantidad][m/h/d] - Generar una nueva clave.\n";
            $response .= "/keys - Ver las claves activas.\n";
            $response .= "/deletekey [key] - Eliminar una clave específica.\n";
            $response .= "/addpremium [id] - Agregar un usuario premium (solo admin).\n";
            $response .= "/mypremium - Ver tu estado premium.\n";
            $response .= "/claim - Reclamar una clave activa.\n";
            $response .= "/listpremiums - Ver todos los usuarios premium (solo admin).\n";
            sendMessage($chatId, $response);
        }

        // Comando /genkey (solo para el admin)
        if (strpos($messageText, '/genkey') === 0 && $chatId == $adminId) {
            $timeRegex = '/(\d+)([mdh])/'; // Para verificar si es m, d o h
            preg_match($timeRegex, $messageText, $matches);

            if (count($matches) < 3) {
                sendMessage($chatId, "❌ Error: Debes especificar la cantidad y el tiempo (m=minutos, h=horas, d=días). Ejemplo: /genkey 5m");
                return;
            }

            $duration = $matches[1];
            $unit = $matches[2];
            $expirationDate = null;

            // Calcular la fecha de expiración
            switch ($unit) {
                case 'm':
                    $expirationDate = date("Y-m-d H:i:s", strtotime("+{$duration} minutes"));
                    break;
                case 'h':
                    $expirationDate = date("Y-m-d H:i:s", strtotime("+{$duration} hours"));
                    break;
                case 'd':
                    $expirationDate = date("Y-m-d H:i:s", strtotime("+{$duration} days"));
                    break;
            }

            // Generar una clave aleatoria
            $key = bin2hex(random_bytes(16));

            // Insertar la clave en la base de datos
            $insertKeyQuery = "INSERT INTO keys (chat_id, key, expiration, claimed) VALUES ($1, $2, $3, FALSE)";
            $insertKeyResult = pg_query_params($conn, $insertKeyQuery, array($chatId, $key, $expirationDate));

            if ($insertKeyResult) {
                sendMessage($chatId, "✅ Clave generada: $key. Expirará en {$duration} {$unit}.");
            } else {
                throw new Exception("Error al generar la clave: " . pg_last_error());
            }
        }

        // Comando /keys (para ver claves activas)
        if ($messageText === '/keys' && $chatId == $adminId) {
            $selectQuery = "SELECT key, expiration FROM keys WHERE claimed = FALSE AND expiration > NOW()";
            $result = pg_query($conn, $selectQuery);

            if (!$result) {
                throw new Exception("Error al obtener las claves activas: " . pg_last_error());
            }

            $keysList = "✅ Claves activas:\n";
            while ($row = pg_fetch_assoc($result)) {
                $keysList .= "Clave: {$row['key']}, Expira: {$row['expiration']}\n";
            }

            if (empty($keysList)) {
                $keysList = "❌ No hay claves activas.";
            }

            sendMessage($chatId, $keysList);
        }

        // Comando /deletekey [key] (para eliminar una clave)
        if (strpos($messageText, '/deletekey') === 0 && $chatId == $adminId) {
            $parts = explode(' ', $messageText);
            if (count($parts) < 2) {
                sendMessage($chatId, "❌ Error: Debes proporcionar una clave para eliminar.");
                return;
            }

            $keyToDelete = $parts[1];
            $deleteQuery = "DELETE FROM keys WHERE key = $1";
            $deleteResult = pg_query_params($conn, $deleteQuery, array($keyToDelete));

            if ($deleteResult) {
                sendMessage($chatId, "✅ Clave $keyToDelete eliminada.");
            } else {
                throw new Exception("Error al eliminar la clave: " . pg_last_error());
            }
        }

        // Comando /addpremium [id] (para agregar a un usuario premium)
        if (strpos($messageText, '/addpremium') === 0 && $chatId == $adminId) {
            $parts = explode(' ', $messageText);
            if (count($parts) < 2) {
                sendMessage($chatId, "❌ Error: Debes proporcionar un chat_id para agregar como premium.");
                return;
            }

            $userIdToAdd = $parts[1];
            $insertPremiumQuery = "INSERT INTO premium_users (chat_id) VALUES ($1) ON CONFLICT (chat_id) DO NOTHING";
            $insertResult = pg_query_params($conn, $insertPremiumQuery, array($userIdToAdd));

            if ($insertResult) {
                sendMessage($chatId, "✅ El usuario con chat_id $userIdToAdd ha sido agregado a los usuarios premium.");
            } else {
                throw new Exception("Error al agregar el usuario a premium: " . pg_last_error());
            }
        }

        // Comando /mypremium (para ver si un usuario es premium)
        if ($messageText === '/mypremium') {
            $selectPremiumQuery = "SELECT chat_id FROM premium_users WHERE chat_id = $1";
            $result = pg_query_params($conn, $selectPremiumQuery, array($chatId));

            if (!$result) {
                sendMessage($chatId, "❌ Error al verificar el estado premium.");
                return;
            }

            if (pg_num_rows($result) > 0) {
                sendMessage($chatId, "✅ Eres un usuario premium.");
            } else {
                sendMessage($chatId, "❌ No eres un usuario premium.");
            }
        }

        // Comando /claim (para reclamar una clave activa)
        if ($messageText === '/claim') {
            // Verificar si el usuario tiene claves activas
            $selectClaimQuery = "SELECT key FROM keys WHERE chat_id = $1 AND claimed = FALSE AND expiration > NOW() LIMIT 1";
            $result = pg_query_params($conn, $selectClaimQuery, array($chatId));

            if ($result && pg_num_rows($result) > 0) {
                $keyRow = pg_fetch_assoc($result);
                $key = $keyRow['key'];

                // Marcar la clave como reclamada
                $updateClaimQuery = "UPDATE keys SET claimed = TRUE WHERE key = $1";
                pg_query_params($conn, $updateClaimQuery, array($key));

                sendMessage($chatId, "✅ Has reclamado la clave: $key.");
            } else {
                sendMessage($chatId, "❌ No tienes claves activas disponibles para reclamar.");
            }
        }

        // Comando /listpremiums (solo para el admin para ver usuarios premium)
        if ($messageText === '/listpremiums' && $chatId == $adminId) {
            $selectPremiumUsersQuery = "SELECT chat_id FROM premium_users";
            $result = pg_query($conn, $selectPremiumUsersQuery);

            if (!$result) {
                throw new Exception("Error al obtener los usuarios premium: " . pg_last_error());
            }

            $premiumUsersList = "✅ Usuarios premium:\n";
            while ($row = pg_fetch_assoc($result)) {
                $premiumUsersList .= "ID: {$row['chat_id']}, Username: @{$row['chat_id']}\n";
            }

            if (empty($premiumUsersList)) {
                $premiumUsersList = "❌ No hay usuarios premium.";
            }

            sendMessage($chatId, $premiumUsersList);
        }

        // Eliminar claves caducadas automáticamente
        $deleteExpiredKeysQuery = "DELETE FROM keys WHERE expiration < NOW()";
        pg_query($conn, $deleteExpiredKeysQuery);

    } catch (Exception $e) {
        error_log("Error en el bot: " . $e->getMessage());
        sendMessage($chatId, "Lo siento, ha ocurrido un error. Por favor, inténtalo de nuevo más tarde.");
    }
}

// Función para enviar mensajes
function sendMessage($chatID, $respuesta, $message_id = null) {
    global $token; // Asegúrate de que el token sea accesible dentro de la función
    $url = "https://api.telegram.org/bot$token/sendMessage?disable_web_page_preview=true&chat_id=".$chatID."&parse_mode=HTML&text=".urlencode($respuesta);
    if ($message_id) {
        $url .= "&reply_to_message_id=".$message_id;
    }
    $response = file_get_contents($url);
    if ($response === FALSE) {
        error_log("Error al enviar mensaje a Telegram: " . print_r(error_get_last(), true));
    }
}
?>
