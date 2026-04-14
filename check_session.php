<?php
declare(strict_types=1);
session_start();
?>
<div style="font-family: monospace; background: #f4f4f4; border: 1px solid #ccc; padding: 15px; margin: 10px;">
    <h2>Contenido de la Sesión Actual</h2>
    <hr>
    <pre><?php print_r($_SESSION); ?></pre>
    <hr>
    <h3>Diagnóstico</h3>
    <?php
    if (isset($_SESSION['zbx_user_type_DEBUG'])) {
        echo '<p style="color: blue;"><b>Log de Debug:</b> ' . htmlspecialchars($_SESSION['zbx_user_type_DEBUG']) . '</p>';
    } else {
        echo '<p style="color: red;"><b>ERROR:</b> La variable <i>zbx_user_type_DEBUG</i> no existe. Esto confirma que el archivo <b>login.php</b> en el servidor NO es la versión que te envié (la que tiene el código de debug).</p>';
    }
    
    if (isset($_SESSION['zbx_user_type'])) {
         echo '<p style="color: green;"><b>Tipo de Usuario:</b> ' . htmlspecialchars((string)$_SESSION['zbx_user_type']) . '</p>';
    } else {
         echo '<p style="color: red;"><b>ERROR:</b> <i>zbx_user_type</i> no está definido.</p>';
    }
    ?>
</div>