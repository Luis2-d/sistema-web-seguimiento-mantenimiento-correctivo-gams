# SISTEMA WEB DE SEGUIMIENTO DEL MANTENIMIENTO CORRECTIVO DE EQUIPOS TECNOLOGICOS PARA LA JEFATURA DE EDUCACION DEL G.A.M.S

Aplicación Laravel 12 para la Jefatura de Educación del Gobierno Autónomo Municipal de Sacaba. Digitaliza solicitudes de soporte, seguimiento por estados, intervenciones técnicas, equipos, repuestos, alertas internas e historial técnico.

Aplicación publicada: [seguimiento-tecnologico1.infy.click](https://seguimiento-tecnologico1.infy.click).

## Requisitos

- PHP 8.2 o superior, con las extensiones requeridas por Composer y `pdo_mysql`.
- Composer 2.
- Node.js 20 desde 20.19.0, o Node.js 22.12.0 o superior, con npm; es el requisito de Vite en `package-lock.json`.
- MySQL o MariaDB; puedes usar el servicio de XAMPP.
- Git para clonar el repositorio.

## Instalación local

Clona el proyecto e instala las dependencias PHP desde su carpeta:

```bash
git clone https://github.com/Luis2-d/sistema-web-seguimiento-mantenimiento-correctivo-gams.git
cd sistema-web-seguimiento-mantenimiento-correctivo-gams
composer install
php -r "file_exists('.env') || copy('.env.example', '.env');"
```

Inicia MySQL/MariaDB y crea una base vacía llamada `gestion_seguimiento` con codificación `utf8mb4`. Edita `.env`: configura `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`, y establece `APP_URL=http://localhost:8000`.

Para completar esta primera instalación:

```bash
php artisan key:generate
php artisan migrate --seed
npm ci
npm run build
php artisan serve
```

Abre `http://localhost:8000`. El comando `migrate --seed` crea las tablas y los datos de demostración. En una instalación que ya contiene datos, aplica cambios con `php artisan migrate`: el sembrado de demostración está pensado para una base nueva.

Para editar estilos o JavaScript con recarga automática, ejecuta `npm run dev` en otra terminal mientras mantienes `php artisan serve` activo. Para generar los archivos finales vuelve a ejecutar `npm run build`.

## APIs del mapa y calendario

Configura las integraciones en tu archivo `.env` con estas variables:

```dotenv
TOMTOM_API_KEY=
GOOGLE_CALENDAR_CLIENT_ID=
GOOGLE_CALENDAR_CLIENT_SECRET=
GOOGLE_CALENDAR_REDIRECT_URI=
GOOGLE_CALENDAR_ID=
```

- **TomTom:** coloca una clave con acceso a mapas, búsqueda y rutas. El mapa utiliza esa clave para mostrar unidades, buscar direcciones y dibujar el recorrido de **Cómo llegar**. Las coordenadas del centro inicial se ajustan con `TOMTOM_DEFAULT_LATITUDE` y `TOMTOM_DEFAULT_LONGITUDE`.
- **Google Calendar:** configura el cliente OAuth y el calendario institucional. Para este inicio local, la URI de retorno es `http://localhost:8000/calendario/google/callback`; debe coincidir con la registrada en Google. Entra como administrador y pulsa **Conectar con Google Calendar** desde el calendario. La [guía de Google Calendar](documentos/Google_Calendar.md) explica la configuración completa.

Después de cambiar la configuración, ejecuta `php artisan config:clear`. Puedes usar las solicitudes y guardar visitas sin conectar Google; su sincronización requiere completar esa conexión. El mapa necesita la clave de TomTom.

El archivo `.env`, las credenciales, los datos locales y los respaldos no se versionan. Comparte `.env.example` con valores vacíos y conserva `composer.lock` y `package-lock.json` para instalar las mismas versiones de dependencias.

## Usuarios de demostración

El sembrado crea estas cuentas para probar el sistema. Todas utilizan la contraseña `password`; cambia sus contraseñas antes de usar la instalación con datos reales.

- Administrador: `admin@educacion.test`
- Técnico: `tecnico@educacion.test`
- Director: `director@educacion.test`

## Flujo principal

1. El director registra una solicitud de hardware, software o conectividad.
2. El administrador o técnico recibe una notificación y asigna al responsable.
3. El técnico cambia estados y registra diagnóstico, solución y repuestos.
4. Al marcarla como solucionada, el director recibe una notificación.
5. El historial conserva todos los cambios e intervenciones.

## Publicación en InfinityFree

La [guía de publicación](deploy/infinityfree/README.md) incluye preparación del paquete, configuración privada, importación de MySQL, transferencia y verificación. Ejecuta `deploy/infinityfree/preparar.ps1` para generar una copia de producción dentro de `output/infinityfree/`, conservando el entorno de desarrollo. Los archivos generados, SQL y secretos quedan excluidos de Git.

El [informe de la actividad 17](documentos/Informe_Publicacion_InfinityFree_BORRADOR.docx) es un borrador con resultados locales comprobados; la URL, las capturas y la verificación del hosting se completan después del despliegue real.
