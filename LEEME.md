# Zabbix PDF Report Generator

Creado por **Axel Del Canto**.

<p align="center">
  <a href="https://github.com/axel250r/zabbix-multiversion-V2/stargazers"><img alt="Estrellas" src="https://img.shields.io/github/stars/axel250r/zabbix-multiversion-V2?style=for-the-badge&logo=github&label=Estrellas"></a>
  <a href="https://paypal.me/axel250r"><img alt="Donar" src="https://img.shields.io/badge/Donar-PayPal-00457C?logo=paypal&logoColor=white&style=for-the-badge"></a>
</p>

> **Guía paso a paso con capturas:** [Guía del informe PDF](https://github.com/axel250r/How-To/blob/main/pdf-report/HOWTO-ES.md). Si la herramienta te ahorra tiempo, una ⭐ en el repo ayuda a que otros administradores de Zabbix la encuentren.

Una herramienta web sencilla y potente para seleccionar ítems de Zabbix y exportar sus gráficos históricos a un informe en formato PDF.

---

## Características Principales ✨

* **Exportación a PDF:** Genera informes profesionales en PDF con los gráficos de los ítems seleccionados.
* **Selección Múltiple:** Interfaz intuitiva para seleccionar hosts, grupos de hosts y plantillas.
* **Paginación Inteligente:** Navega fácilmente a través de cientos de hosts, grupos y plantillas.
* **Filtro Dinámico:** Encuentra rápidamente lo que buscas con filtros en tiempo real.
* **Soporte Multi-idioma:** Interfaz disponible en Español e Inglés.
* **Personalizable:** Permite reemplazar el logo por defecto y soporta tema claro/oscuro.
* **Seguro:** Implementa protección contra CSRF y una Política de Seguridad de Contenido (CSP).
* **Licencia Open Source:** Protegido bajo la licencia GNU GPLv3.

---

## Configuración Rápida

Para que la aplicación funcione en tu propio servidor Zabbix, solo necesitas seguir dos pasos:

#### **Paso 1: Copiar la Carpeta del Proyecto**

Copia la carpeta completa del proyecto (`zabbix-pdf-report/`) a un directorio accesible por tu servidor web (por ejemplo, `/var/www/html/` o `/usr/share/zabbix/`).

#### **Paso 2: Crear y Editar el Archivo `config.php`**

Este es el único archivo que necesitas modificar.

1.  En la raíz del proyecto, busca el archivo `config.php.example`.
2.  Haz una copia de este archivo y renómbrala a `config.php`.
3.  Abre tu nuevo `config.php` y edita las siguientes líneas:
4.  Si deseas agregar el boton de PDF Reporter en el front de Zabbix debes hacer lo siguiente:

Editar /usr/share/zabbix/include/classes/helpers/CMenuHelper.php

Buscar esta linea: $submenu_reports = array_filter($submenu_reports);

Arriba de la linea mencionada pegar esto:

$submenu_reports[] = CWebUser::checkAccess(CRoleHelper::UI_REPORTS_SYSTEM_INFO)
    ? (new CMenuItem(_('Informe PDF')))
          ->setUrl(new CUrl('zabbix-pdf-report/login.php'), true)
          ->setId('report_pdf')
          // aquí reemplaza setAlias() por setAliases()
          ->setAliases(['zabbix-pdf-report/chooser.php'])
    : null;

```php
<?php
// config.php

/**
 * URL del frontend de Zabbix.
 * Modifica esta línea con la URL de tu Zabbix.
 * Ejemplo: '[http://192.168.1.100/zabbix](http://192.168.1.100/zabbix)'
 */
define('ZABBIX_URL', 'http://tu-zabbix.com/zabbix');

/**
 * URL del endpoint de la API de Zabbix.
 * Generalmente no necesitas cambiar esta línea.
 */
define('ZABBIX_API_URL', ZABBIX_URL . '/api_jsonrpc.php');

/**
 * (Opcional) Ruta al logo personalizado.
 * Para usar tu propio logo, descomenta esta línea y pon la ruta a tu imagen.
 * La ruta debe ser relativa a la raíz del proyecto.
 * Ejemplo: define('CUSTOM_LOGO_PATH', 'assets/mi_logo.png');
 */
// define('CUSTOM_LOGO_PATH', 'assets/tu_logo_personalizado.png');