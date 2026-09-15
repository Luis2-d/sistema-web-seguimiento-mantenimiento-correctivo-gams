# Google Calendar en Gestión de Seguimiento

## Uso en el sistema

- Abre **Calendario de visitas** desde el menú.
- Para programar: entra a una solicitud abierta y despliega **Programar visita**. Selecciona técnico, inicio e indicaciones. Las visitas nuevas duran una hora automáticamente; al reprogramar se conserva su duración.
- El horario corresponde a Bolivia (America/La_Paz). Se almacena en UTC para conservar la hora correcta.
- El administrador puede programar para técnicos activos. El técnico puede programarse visitas en solicitudes propias o sin asignar. El director consulta las de su unidad.
- Si la solicitud ya tiene técnico responsable, programa la visita para ese técnico. Para elegir otro, cambia primero la asignación de la solicitud. En solicitudes sin asignar puedes elegir un técnico activo; la programación no cambia por sí sola la asignación.
- No se permite superponer visitas del mismo técnico. Puedes reprogramar o cancelar desde la solicitud. Una visita cancelada se conserva como historial; para volver a atender, crea otra.
- Resolver o cerrar una solicitud no cancela automáticamente sus visitas. Cancela explícitamente las que ya no se realizarán.
- Las visitas quedan guardadas aunque Google esté desconectado. Usa **Sincronizar** para enviar las pendientes cuando se restablezca la conexión.

## Conexión inicial (administrador)

1. En https://console.cloud.google.com/ crea o selecciona un proyecto y habilita **Google Calendar API**.
2. En Google Auth Platform configura el nombre de la aplicación, contacto y audiencia. Si está en modo de prueba, agrega tu cuenta como usuario de prueba.
3. Crea un cliente OAuth 2.0 de tipo **Aplicación web**. En URI de redireccionamiento autorizados pega la dirección que muestra **Calendario de visitas → Configuración de la conexión**. Tiene que coincidir en protocolo, dominio, puerto y ruta.
4. En Google Calendar crea un calendario dedicado, por ejemplo **Visitas técnicas – Soporte Educativo**. En su configuración, sección **Integrar el calendario**, copia el identificador. Utiliza una cuenta que tenga permiso para modificar sus eventos.
5. Agrega estas variables al `.env` del proyecto, con los valores de tu cuenta:

```dotenv
GOOGLE_CALENDAR_CLIENT_ID=
GOOGLE_CALENDAR_CLIENT_SECRET=
GOOGLE_CALENDAR_REDIRECT_URI=http://localhost:8000/calendario/google/callback
GOOGLE_CALENDAR_ID=
```

La dirección de ejemplo solo corresponde a `php artisan serve` en el puerto 8000. Si usas otro dominio o ruta de XAMPP, usa la dirección real que muestra el sistema. No mezcles `localhost` con `127.0.0.1` entre el inicio de la conexión y el retorno.

6. Ejecuta `php artisan config:clear`. Si instalas estos cambios en otra copia, ejecuta también `php artisan migrate`.
7. Entra como administrador, abre el calendario y pulsa **Conectar con Google Calendar**. Acepta el permiso para administrar eventos.
8. Programa una visita de prueba y comprueba que aparece en el calendario de Google. Reprograma la misma visita: debe actualizarse el mismo evento. Al cancelarla, el evento se elimina de Google y el historial permanece en el sistema.

Los tokens se guardan cifrados en la base de datos usando `APP_KEY`; no cambies esa clave sin planificar la migración de los datos cifrados. No compartas el secreto de cliente ni los tokens. El modo de prueba de OAuth puede exigir reconectar periódicamente por vencimiento del acceso; para uso institucional continuo completa la publicación y los requisitos que indique Google.

## Alcance de la sincronización

La sincronización es **del sistema hacia Google**. Los cambios hechos directamente en Google no se importan y pueden sobrescribirse al sincronizar. Se guarda un identificador estable por visita para evitar duplicados al reintentar. Los eventos incluyen solicitud, unidad, técnico, indicaciones, horario y un recordatorio de 30 minutos. No se agregan asistentes ni se envían invitaciones por correo; el recordatorio se configura en Google y su entrega depende del usuario y su dispositivo.

Desconectar borra las credenciales locales y detiene los envíos; conserva las visitas y los eventos ya creados. Para revocar por completo el permiso, retira el acceso de la aplicación desde la cuenta de Google. Si cambias de calendario, las visitas vinculadas al anterior requieren reconectarlo para actualizarse: no se migran automáticamente.

El desarrollo local permite autorizar y enviar eventos con Internet. Los enlaces del evento hacia `localhost` solo funcionan en la computadora del proyecto; para acceso desde otros equipos configura el dominio real en `APP_URL`.

## Referencias técnicas

- OAuth para aplicaciones web: https://developers.google.com/identity/protocols/oauth2/web-server
- Eventos: https://developers.google.com/workspace/calendar/api/guides/create-events
- Permisos: https://developers.google.com/workspace/calendar/api/auth
