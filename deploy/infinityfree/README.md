# Publicación en InfinityFree

Herramientas locales para preparar la aplicación existente. No subas `deploy/` al hosting.

## Archivos preparados

Ejecutar desde la raíz del proyecto:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File deploy/infinityfree/preparar.ps1
```

La salida se guarda en `output/infinityfree/paquete-FECHA-HORA/`. `estado.json` señala el paquete más reciente. Se incluye:

- `htdocs/`: aplicación y dependencias de producción, recursos compilados y reglas de acceso.
- `privado-NO-SUBIR/.env.production`: copia privada de la configuración, con APP_KEY y claves de integraciones conservadas. Faltan los datos del hosting.
- `privado-NO-SUBIR/base-datos.sql`: exportación de la base local, si MySQL estaba disponible. Contiene datos y hashes de contraseñas; conserva la estructura, pero omite los registros temporales de sesiones, caché, restablecimientos y colas. Importar solamente en phpMyAdmin; nunca alojar en la web ni subir a GitHub.
- `privado-NO-SUBIR/resumen-base.json`: cantidades por tabla, sin registros ni credenciales.

El script conserva el entorno original. La copia no incluye documentos académicos, Git, herramientas de desarrollo, sesiones, logs ni caché local. Los ficheros que excedan los límites del proveedor detienen la preparación.

## Completar en el panel

1. Crear una cuenta gratuita en https://www.infinityfree.com/ y elegir un subdominio.
2. Abrir la cuenta activa y crear una base en **MySQL Databases**.
3. Copiar el host, nombre completo y usuario de MySQL que muestra el panel. El host no es el `localhost` de XAMPP.
4. Configurar el paquete con los datos del panel; usar los valores propios en lugar de estos ejemplos:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File deploy/infinityfree/configurar-hosting.ps1 -Url "https://TU-SUBDOMINIO.rf.gd" -DbHost "sqlXXX.infinityfree.com" -DbName "if0_XXXX_seguimiento" -DbUser "if0_XXXX"
```

La contraseña se solicita de forma oculta y se guarda localmente. No enviarla por chat. El script crea `htdocs/.env`. No generar otra APP_KEY al importar la base existente: `conexiones_calendario` contiene tokens cifrados con la clave original.

5. Abrir **phpMyAdmin**, seleccionar la base vacía e importar `privado-NO-SUBIR/base-datos.sql`. Importar estructura y datos. Si ya hay datos de usuarios en el hosting, no reemplazar la base completa para actualizar código.
6. Conectar FileZilla con los datos de **FTP Details**, o utilizar el administrador de archivos.
7. Dentro del `htdocs` que corresponde al subdominio, subir PRIMERO el archivo `.htaccess` de `htdocs/`. Confirmar que existe antes de subir `.env` y el resto.
8. Subir EL CONTENIDO de `htdocs/` al `htdocs` remoto. No crear una carpeta adicional del paquete. Conservar `public/.htaccess`. Incluir archivos ocultos y carpetas vacías de `storage`.
9. Comprobar HTTPS en el subdominio sin `www`. Los subdominios gratuitos soportados tienen SSL automático.
10. Registrar en Google Cloud la URI autorizada `https://TU-SUBDOMINIO/calendario/google/callback` y probar la conexión al calendario. Revisar las restricciones de sitio de la clave TomTom.

## Verificación y capturas

- Abrir `/login`, iniciar sesión y probar solicitudes, equipos, repuestos, reportes y permisos por rol.
- Verificar mapa, búsqueda de rutas y conexión a Google Calendar con la cuenta autorizada.
- Confirmar que `/.env`, `/composer.json`, `/storage/logs/laravel.log` y `/vendor/autoload.php` no muestran archivos privados.
- Apagar XAMPP y abrir el enlace en otro equipo o celular con datos móviles.
- Guardar capturas del subdominio activo, base importada, archivos remotos, HTTPS, panel y una operación guardada. Ocultar claves, contraseñas y datos personales.
- Completar el informe con la URL real y resultados reales. Las pruebas locales no sustituyen la verificación del hosting.

Si aparece error 500, revisar `storage/logs/laravel.log` mediante FTP, sin publicar su contenido. Comprobar que `storage` y `bootstrap/cache` sean escribibles y que no falten archivos. No activar depuración públicamente para la entrega.

## Actualizaciones

Preparar una nueva copia con el script; configurar sus datos y subir los archivos actualizados. Los CSS/JS de Vite se recompilan. Conservar el `.env` de producción, la base del hosting y sus archivos de usuario. Tras cambios de esquema, aplicar solamente los cambios SQL necesarios, con respaldo previo. InfinityFree no sincroniza automáticamente este repositorio.

## Fuentes

- Instalación Laravel: https://forum.infinityfree.com/t/how-to-install-a-laravel-site-on-infinityfree/118578
- Límites: https://forum.infinityfree.com/t/file-size-and-upload-limits/49308
- Base de datos: https://forum.infinityfree.com/t/how-to-setup-a-new-mysql-database/49342/1
- SSL: https://forum.infinityfree.com/t/how-to-get-free-ssl-https-on-infinityfree/49323
