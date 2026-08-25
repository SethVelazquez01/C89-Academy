# C89 Academy

Plataforma de capacitación empresarial construida con Laravel 13, Livewire 4, Flux UI y Tailwind CSS 4.

## Requisitos

- PHP 8.3 o superior con SQLite habilitado
- Composer 2
- Node.js 22 o superior
- Git

En Windows se recomienda Laravel Herd para disponer de PHP, Composer y un dominio local.

## Instalación en una computadora nueva

```bash
git clone https://github.com/SethVelazquez01/C89-Academy.git
cd C89-Academy
composer setup
php artisan db:seed --no-interaction
composer run dev
```

La aplicación utiliza SQLite de forma predeterminada. `composer setup` instala las dependencias, crea `.env`, genera una clave, ejecuta las migraciones y compila los recursos del frontend.

Si se utiliza Laravel Herd, el proyecto también puede abrirse desde `http://c89-academy.test` después de vincular la carpeta en Herd.

## Usuarios locales de demostración

El seeder local crea estos usuarios con la contraseña `password`:

- Propietario de plataforma: `owner@c89.com.mx`
- Administrador: `admin@c89.com.mx`
- Colaborador: `colaborador@c89.com.mx`

Estas cuentas solo se crean en los entornos `local` y `testing`. Deben reemplazarse antes de publicar la aplicación.

## Comandos habituales

```bash
composer run dev
composer test
php artisan migrate --seed
npm run build
```

## Archivos que no se guardan en Git

Por seguridad, el repositorio no incluye `.env`, la base SQLite local, sesiones, archivos temporales, dependencias instaladas ni documentos privados cargados por usuarios. En una instalación nueva se reconstruyen la aplicación y su estructura mediante `.env.example`, migraciones y seeders.

Para trasladar datos reales entre equipos se debe utilizar una copia privada de la base de datos y de `storage/app/private`, nunca agregarlos directamente al historial de Git.

## Verificación

El flujo de integración continua de GitHub ejecuta el formateo, el análisis estático y todas las pruebas al enviar cambios a `main` o abrir un pull request.
