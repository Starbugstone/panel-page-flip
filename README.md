# THIS PROJECT IS AN EXPERIANCE WITH VIBE CODING
I did not write a single line of code, or even docker setup for the dev environment (well, I did one line but the bot was being so stupid and adding 10 files and even .sh files for a one line php fix !!)

I did some reviews just to check the code and then guide the bot after to correct major bugs

I had to help the bot with many console errors, cutting him off and reguiding him before he went haywire. 

note : always test after each change and use git often. And tell the bot never to commit by himself or you will be cherry picking like hell (lil bastard almost crashed the entire project once, thank god for git)

Tools used :
- Lovable: frontend and init of the project
- windsurf with Claude 3.7 and gemini 2.5 pro for most of debugging, construction of the backend and plugging into front end
- google jules mostly to init extra features and then a pass (or 20) with claude and gemini to fix the errors, but easier than repassing via lovable as they don't allow branch creation / changing (I don't like to commit to main on major updates)

I will add an extra paragraph if I feel up to in at the end of the project to give my full recomendations on each tool and the experience

# CBZ Comic Reader

(yes, even the readme was done by AI !! apart from my little extra just before, after this, all is 100% pure free range computer)

## Project Overview

CBZ Comic Reader is a web application that allows users to read comic books in CBZ format. The application features a secure login system, a comic selection interface, and a reading progress tracker that remembers where you left off.

## Initial Project Requirements

The following prompt was used to initiate this project:

```
I need a front end for my new web app. It will be a comic book reader to read cbz files.
The files will only be accessible when logged in so I will need a landing page to log in first.
Then I will need a select comic section / page to select the comic I want to read, probably taken from the database / backend.
The Database / backend will also know the history of the reading to be able to jump back to the last read page

The backend will be handled by symfony.

The aspect shuold be fun, minimalist with a comic book vibe. The actual read comic page should be very minimalist so the user can concentrate on reading the actual comic.
The site should have a norma and dark mode.
```

## Features

- **User Authentication**: Secure login system to protect your comic collection
- **Email Verification**: Email verification required before users can log in
- **Password Recovery**: Forgot password functionality with email recovery
- **Comic Library**: Browse and select from your collection of comics
- **Reading Progress**: Automatically saves your reading position
- **CBZ Format Support**: Read comics in the popular CBZ archive format
- **Chunked Uploads**: Support for large file uploads via chunking (1MB chunks)
- **Upload Progress**: Real-time progress tracking during file uploads
- **Advanced Caching**: Smart page caching system that prevents unnecessary network calls
- **Fast Navigation**: Immediate display of cached pages for smooth reading experience
- **Memory Optimization**: Efficient memory usage by only caching pages within the current reading window
- **Responsive Design**: Optimized for both desktop and mobile devices
- **Dark Mode**: Toggle between light and dark themes for comfortable reading
- **Custom Tagging**: Create and assign custom tags to your comics for better organization
- **Comic Sharing**: Share comics with other users via email invitations
- **Pending Shares Management**: Accept or refuse comics shared with you
- **Automatic Cleanup**: System automatically cleans up expired share tokens and their public cover images
- **Session Persistence**: Actively maintains user sessions to prevent unexpected logouts during activity or long uploads.
- **Dropbox Integration**: Connect your Dropbox account to automatically sync CBZ files from your Dropbox folder
- **Automatic Sync**: Background sync command that can be scheduled via cron to automatically import new comics
- **Dropbox Comics Management**: Dedicated dashboard section for comics synced from Dropbox

## Architecture

The project is split into two main components:

### Frontend

The frontend is built with:

- React with JavaScript
- Vite for fast development and building
- shadcn-ui components
- Tailwind CSS for styling
- React Router for navigation

### Backend

The backend is powered by:

- Symfony PHP framework
- MySQL database for storing user data and reading progress
- Docker for containerization and easy setup

## API Endpoints

The backend provides the following API endpoints:

### Authentication

- `POST /api/login` - Login with email and password
- `POST /api/register` - Register a new user
- `POST /api/logout` - Logout the current user
- `GET /api/login_check` - Check if the user is authenticated
- `GET /api/users/me` - Get the current user's information. Also supports `POST` requests to refresh the user's session (keep-alive).
- `POST /api/forgot-password` - Request a password reset email
- `GET /api/reset-password/validate/{token}` - Validate a password reset token
- `POST /api/reset-password/reset/{token}` - Reset password with a valid token

### Comics

- `GET /api/comics` - Get all comics for the current user
- `GET /api/comics/{id}` - Get a specific comic by ID
- `POST /api/comics` - Upload a new comic (multipart/form-data with file, title, and optional fields)
- `POST /api/comics/upload/init` - Initialize a chunked upload (for large files)
- `POST /api/comics/upload/chunk` - Upload a single chunk of a comic file
- `POST /api/comics/upload/complete` - Complete a chunked upload
- `PUT/PATCH /api/comics/{id}` - Update a comic's information
- `DELETE /api/comics/{id}` - Delete a comic
- `GET /api/comics/{id}/pages/{page}` - Get a specific page from a comic
- `POST /api/comics/{id}/progress` - Update reading progress for a comic

### Tags

- `GET /api/tags` - Get all tags
- `POST /api/tags` - Create a new tag
- `PUT/PATCH /api/tags/{id}` - Update a tag
- `DELETE /api/tags/{id}` - Delete a tag

### Comic Sharing

- `POST /api/share/comic/{comicId}` - Share a comic with another user via email
- `GET /api/share/pending` - Get a list of comics shared with the current user
- `POST /api/share/accept/{token}` - Accept a shared comic
- `POST /api/share/refuse/{token}` - Refuse a shared comic

### Dropbox Integration

- `GET /api/dropbox/connect` - Initiate Dropbox OAuth connection
- `GET /api/dropbox/callback` - Handle Dropbox OAuth callback
- `GET /api/dropbox/status` - Check Dropbox connection status
- `POST /api/dropbox/disconnect` - Disconnect Dropbox account
- `GET /api/dropbox/files` - List CBZ files in connected Dropbox account
- `POST /api/dropbox/sync` - Manually trigger sync of Dropbox files

### User Management (Admin only)

- `GET /api/users` - Get all users (admin only)
- `GET /api/users/{id}` - Get a specific user
- `PUT/PATCH /api/users/{id}` - Update a user
- `DELETE /api/users/{id}` - Delete a user

## Project Structure

```
./
├── frontend/           # React frontend application
│   ├── src/            # React source code
│   │   ├── components/ # UI components
│   │   ├── hooks/      # Custom React hooks including authentication
│   │   ├── pages/      # Page components
│   │   └── lib/        # Utility functions
│   ├── public/         # Static assets for frontend
│   └── package.json    # Frontend dependencies
│   └── ...             # Other frontend config files
├── backend/            # Symfony backend application
│   ├── src/            # Symfony source code
│   │   ├── Controller/ # API controllers
│   │   ├── Entity/     # Database entities
│   │   ├── Security/   # Authentication handlers
│   │   └── Command/    # CLI commands
│   └── ...             # Other Symfony files and folders
├── docker/             # Docker configuration files
│   ├── php/            # Dockerfile and config for PHP/Symfony service
│   ├── nginx_frontend/ # Dockerfile and config for Nginx (serves frontend & proxies backend)
│   └── .env            # Environment variables for Docker
├── docker-compose.yml  # Docker Compose configuration for all services
├── .env                # Main environment variables file
└── .dockerignore       # Specifies intentionally untracked files for Docker context
```

## Setup Instructions

### Prerequisites

- Docker and Docker Compose

### Development Setup

```sh
# Start all services with Docker from the project root directory
docker compose up -d

# The application (frontend and backend API) will be available at http://localhost:8080
For frontend development with live reload, a dedicated service is available. See the 'Frontend Development with Live Reload' section below for details.
# API endpoints are generally prefixed with /api/
# To stop the services:
# docker compose down
```

The first time you run the containers:
- The `php` service's setup script will automatically install a new Symfony project in the `backend/` directory, install required Symfony packages, and configure the database connection if not already present.
- The `nginx` service will build the React frontend application (from the `frontend/` directory) and configure Nginx to serve it and proxy API calls to the PHP backend.

## Environment Configuration

The project uses several environment files:

- `.env` - Main environment file in the project root with Docker configuration (ports, service names, database credentials, etc.)
- `frontend/.env` - Vite/React frontend specific environment variables (if any, loaded by Vite)
- `backend/.env` - Default Symfony environment variables
- `backend/.env.local` - Local overrides for Symfony environment variables (database connection, mailer settings, etc.)

### Dropbox Configuration

The Dropbox integration allows users to sync their comic collections from their personal Dropbox accounts. Each user connects their own Dropbox account to the application.

#### Environment Variables

Add these environment variables to your `backend/.env` or `backend/.env.local`:

```env
# =============================================================================
# DROPBOX INTEGRATION CONFIGURATION
# =============================================================================
# Dropbox App Credentials (get from https://www.dropbox.com/developers/apps)
DROPBOX_APP_KEY=your_dropbox_app_key_here
DROPBOX_APP_SECRET=your_dropbox_app_secret_here

# Dropbox OAuth Redirect URI (must match exactly in Dropbox app settings)
DROPBOX_REDIRECT_URI=http://localhost:8080/api/dropbox/callback

# Dropbox App Folder Configuration
# This is the folder path in each user's Dropbox where comics will be synced from
# Default: /Applications/StarbugStoneComics (created automatically when users connect)
DROPBOX_APP_FOLDER=/Applications/StarbugStoneComics

# Dropbox Sync Configuration
# Maximum number of files to sync per user per sync operation (prevents overload)
DROPBOX_SYNC_LIMIT=10

# Dropbox Rate Limiting (requests per minute to prevent API limits)
DROPBOX_RATE_LIMIT=60
```

#### Setting up Dropbox App

**Step-by-Step Setup:**
1. **Go to Dropbox App Console**: https://www.dropbox.com/developers/apps
2. **Create New App**:
   - Click "Create app"
   - Choose "Scoped access"
   - Choose "App folder" (recommended) or "Full Dropbox"
   - Name your app (e.g., "StarbugStoneComics")
3. **Configure Permissions**:
   - Go to the "Permissions" tab
   - Enable these scopes:
     - ✅ `files.metadata.read` (required for listing files)
     - ✅ `files.content.read` (required for downloading files)
     - ✅ `files.content.write` (optional, for future upload features)
4. **Set Redirect URI**:
   - Go to the "Settings" tab
   - Add your redirect URI: `http://localhost:8080/api/dropbox/callback`
   - For production: `https://yourdomain.com/api/dropbox/callback`
5. **Get Credentials**:
   - Copy the "App key" and "App secret"
   - Add them to your environment variables

#### Environment-Specific Configuration

**Development:**
```env
DROPBOX_APP_KEY=your_dev_app_key
DROPBOX_APP_SECRET=your_dev_app_secret
DROPBOX_REDIRECT_URI=http://localhost:8080/api/dropbox/callback
```

**Production:**
```env
DROPBOX_APP_KEY=your_prod_app_key
DROPBOX_APP_SECRET=your_prod_app_secret
DROPBOX_REDIRECT_URI=https://yourdomain.com/api/dropbox/callback
```

**Staging:**
```env
DROPBOX_APP_KEY=your_staging_app_key
DROPBOX_APP_SECRET=your_staging_app_secret
DROPBOX_REDIRECT_URI=https://staging.yourdomain.com/api/dropbox/callback
```

## Dropbox Sync Command

The application includes a console command for automatically syncing comics from Dropbox. The command uses configurable defaults from your environment variables.

### Command Usage

```bash
# Sync all users (uses DROPBOX_SYNC_LIMIT from .env, default: 10 files per user)
php bin/console app:dropbox-sync

# Sync with custom limit (overrides environment default)
php bin/console app:dropbox-sync --limit=5

# Sync specific user only
php bin/console app:dropbox-sync --user-id=123

# Dry run (see what would be synced without actually syncing)
php bin/console app:dropbox-sync --dry-run

# Combine options
php bin/console app:dropbox-sync --user-id=123 --limit=20 --dry-run
```

### Configuration

The sync command respects these environment variables:

- **`DROPBOX_SYNC_LIMIT`**: Default number of files to sync per user (default: 10)
- **`DROPBOX_APP_FOLDER`**: Folder path to scan in each user's Dropbox (default: /Applications/StarbugStoneComics)
- **`DROPBOX_RATE_LIMIT`**: API rate limiting (default: 60 requests per minute)

### Automated Sync with Cron

To automatically sync comics at midnight every day, add this to your crontab:

```bash
# Sync with environment default limit (10 files per user)
0 0 * * * cd /path/to/your/project && php bin/console app:dropbox-sync

# Sync with custom limit
0 0 * * * cd /path/to/your/project && php bin/console app:dropbox-sync --limit=5

# Sync every 6 hours with rate limiting
0 */6 * * * cd /path/to/your/project && php bin/console app:dropbox-sync --limit=3
```

The sync command will:
- Find all users with connected Dropbox accounts
- Recursively scan their Dropbox app folder for CBZ files in any subfolder
- Download up to the specified limit of new files per user
- Automatically create tags based on folder structure (e.g., `superHero` → "Super Hero", `Manga/Anime` → "Manga" + "Anime")
- Create comic entries with "Dropbox" tag plus folder-based tags
- Store files in user-specific `uploads/comics/{user_id}/dropbox/` directories

### Folder-Based Tagging

The system automatically creates tags from your Dropbox folder structure. Organize your files in your configured app folder (default: `Applications/StarbugStoneComics`) using subfolders to automatically generate meaningful tags.

**Quick Organization Guide:**
- Create folders in your app directory (configured via `DROPBOX_APP_FOLDER`)
- Each subfolder becomes a tag automatically
- Supports nested folders for hierarchical organization
- Smart naming conversion handles various conventions
- App folder name itself is excluded from tags

**Examples (with default `DROPBOX_APP_FOLDER=/Applications/StarbugStoneComics`):**
- `Applications/StarbugStoneComics/Superman.cbz` → Tags: ["Dropbox"]
- `Applications/StarbugStoneComics/superHero/Batman.cbz` → Tags: ["Dropbox", "Super Hero"]
- `Applications/StarbugStoneComics/Manga/Action/naruto.cbz` → Tags: ["Dropbox", "Manga", "Action"]
- `Applications/StarbugStoneComics/sci-fi/space_opera/Foundation.cbz` → Tags: ["Dropbox", "Sci Fi", "Space Opera"]

**With Custom App Folder (`DROPBOX_APP_FOLDER=/Applications/MyComics`):**
- `Applications/MyComics/Superman.cbz` → Tags: ["Dropbox"]
- `Applications/MyComics/Marvel/Spider-Man.cbz` → Tags: ["Dropbox", "Marvel"]

**Naming Conventions Supported:**
- camelCase: `superHero` → "Super Hero"
- snake_case: `space_opera` → "Space Opera"  
- kebab-case: `sci-fi` → "Sci Fi"
- UPPERCASE: `MANGA` → "Manga"
- PascalCase: `ActionAdventure` → "Action Adventure"

**Common Organization Patterns:**
- By Genre: `Action/`, `Comedy/`, `Drama/`, `Fantasy/`
- By Publisher: `Marvel/`, `DC_Comics/`, `Image/`
- By Series: `Batman/`, `Spider-Man/`, `X-Men/`
- Mixed: `Marvel/superHero/`, `Manga/Action/`, `Indie/sci-fi/`

## Email Testing

The application includes a password reset feature that sends emails. For development and testing purposes, the project includes Mailpit, a modern mail testing tool that captures outgoing emails.

### Mailpit Setup

- Mailpit is included in the Docker Compose configuration
- SMTP server runs on port 1025 (internally) and is mapped to host port 1025
- Web interface runs on port 8025 (internally) and is mapped to host port 8025
- Access the Mailpit web interface at http://localhost:8025

### Email Configuration

The email settings are configured in `backend/.env.local`:

```
# Using Mailpit for email testing
MAILER_DSN=smtp://mailpit:1025
MAILER_FROM_ADDRESS=noreply@comicreader.com
MAILER_FROM_NAME="Comic Reader"
```

### Synchronous vs. Asynchronous Email Delivery

By default, Symfony routes emails through the Messenger component, which queues them for asynchronous delivery. For development purposes, we've configured emails to be sent synchronously by commenting out the email routing in `config/packages/messenger.yaml`:

```yaml
routing:
    # Comment out this line to send emails synchronously
    # Symfony\Component\Mailer\Messenger\SendEmailMessage: async
    Symfony\Component\Notifier\Message\ChatMessage: async
    Symfony\Component\Notifier\Message\SmsMessage: async
```

For production, you should uncomment the email routing line and run a Messenger consumer to process the queue:

## Production Deployment

### Dropbox Configuration for Production

When deploying to production, update your environment variables:

```env
# Production Dropbox Configuration
DROPBOX_APP_KEY=your_production_app_key
DROPBOX_APP_SECRET=your_production_app_secret
DROPBOX_REDIRECT_URI=https://yourdomain.com/api/dropbox/callback
DROPBOX_APP_FOLDER=/Applications/StarbugStoneComics
DROPBOX_SYNC_LIMIT=5
DROPBOX_RATE_LIMIT=30
```

**Important Production Steps:**

1. **Update Dropbox App Settings**:
   - Add production redirect URI to your Dropbox app
   - Ensure all required permissions are enabled
   - Test OAuth flow in production environment

2. **Set Up Automated Sync**:
   ```bash
   # Add to production crontab
   0 2 * * * cd /path/to/production/project && php bin/console app:dropbox-sync --limit=5
   ```

3. **Monitor Sync Performance**:
   - Start with lower sync limits in production
   - Monitor server resources during sync operations
   - Adjust `DROPBOX_SYNC_LIMIT` based on server capacity

4. **Security Considerations**:
   - Use HTTPS for all Dropbox OAuth redirects
   - Secure environment variable storage
   - Regular token refresh monitoring
   - File permission auditing

### CI/CD Integration

The project includes GitHub Actions for automated deployment. The frontend build process is already configured, and the workflow includes TODO comments for backend deployment via SSH.

**Current Workflow:**
- Builds React frontend on PR merge to main
- Uploads frontend build to production via FTP
- Safe mode: Never deletes existing files

**Recommended Backend Deployment:**
```bash
# SSH into production server
cd /path/to/project
git pull origin main
composer install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod
php bin/console doctrine:migrations:migrate --no-interaction
```

```bash
# For production: Run a Messenger consumer to process queued emails
php bin/console messenger:consume async --time-limit=3600
```

### Testing Email Functionality

A Symfony command is available to test email sending:

```bash
# Run via Docker
docker compose exec php bin/console app:test-mail --to=test@example.com

# Options
--to=EMAIL      # Required: Email address to send the test email to
--subject=TEXT  # Optional: Subject of the test email
--body=TEXT     # Optional: Body of the test email
```

## Password Reset Functionality

The application includes a comprehensive password reset workflow with security features:

### Complete Reset Flow

1. **Request Reset**: User clicks "Forgot password?" on the login page and enters their email
2. **Token Generation**: System generates a unique token, stores it in the database with an expiration time
3. **Email Delivery**: System sends an email with a reset link to the frontend (not the API endpoint)
4. **Token Validation**: When user clicks the link, the frontend validates the token with the backend
5. **Password Reset**: User enters and confirms a new password
6. **Confirmation**: System updates the password, invalidates the token, and redirects to login
7. **Security Notification**: System sends a confirmation email notifying the user that their password was changed

### Security Features

- **Token Expiration**: Tokens expire after 24 hours for security
- **One-time Use**: Tokens are invalidated immediately after use
- **Privacy Protection**: System doesn't reveal whether an email exists in the database
- **Change Notification**: Users receive an email notification when their password is changed
- **Auto-redirect**: After successful reset, users are automatically redirected to the login page

### Testing the Password Reset

To test the password reset functionality in development:

1. Click "Forgot password?" on the login page
2. Enter a valid email address (e.g., `testuser1@example.com`)
3. Check the Mailpit interface at http://localhost:8025 to view the reset email
4. Click the reset link in the email to set a new password
5. After setting a new password, you'll be redirected to the login page
6. Check Mailpit again to see the password change notification email

You can customize the ports and other settings by editing the `.env` file in the project root:

```dotenv
# Example from .env
# Ports
NGINX_PORT=8080     # Application access port
MYSQL_PORT=3308     # Host port mapped to MySQL container
ADMINER_PORT=8081   # Host port mapped to Adminer container
```

## Development Workflow

### Frontend Development with Live Reload

The frontend code is in the `frontend/` directory (specifically `frontend/src/`).

For an enhanced development experience with Hot Module Replacement (HMR), a dedicated Vite development server is now configured. To use it:

1.  Ensure all services are running via `docker compose up -d`.
2.  Access the frontend directly through the Vite development server at: **`http://localhost:3001`**.

Changes made to files within the `./frontend` directory will automatically trigger a rebuild and update your browser session live.

The `nginx` service, accessible at `http://localhost:8080` (or your configured NGINX_PORT in the `.env` file), still handles API proxying to the backend and can serve a static production build of the frontend. However, for active development and immediate feedback, `http://localhost:3001` is the recommended URL. You no longer need to rebuild the `nginx` container to see frontend changes during development.

### Backend Development

The backend code is in the `backend/` directory. After making changes to the Symfony code, you may need to clear the cache:

```sh
docker compose exec php bin/console cache:clear
```

### Authentication System

The application features a complete authentication system:

- **API Endpoints**:
  - `/api/login` - Login endpoint (POST)
  - `/api/register` - Registration endpoint (POST)
  - `/api/logout` - Logout endpoint (POST)
  - `/api/login_check` - Check authentication status (GET)
  - `/api/forgot-password` - Request password reset (POST)
  - `/api/reset-password/validate/{token}` - Validate reset token (GET)
  - `/api/reset-password/reset/{token}` - Reset password with token (POST)
  - `/api/email-verification/verify/{token}` - Verify email with token (GET)
  - `/api/email-verification/resend` - Resend verification email (POST)

- **Email Verification**:
  - After registration, users receive a verification email
  - Users must verify their email before they can log in
  - Verification emails can be viewed in the Mailpit interface at http://localhost:8025
  - Users can request a new verification email if needed

- **Password Recovery**:
  - Click "Forgot password?" on the login page
  - Enter your email address to receive a reset link
  - Check the Mailpit interface at http://localhost:8025 to view the reset email
  - Click the reset link to set a new password

- **User Management**:
  - Create new users with the registration form
  - Manage users with the command line tool:
  ```sh
  docker compose exec php bin/console app:create-user email@example.com password
  ```

### Database Access

You can access the MySQL database using your preferred database client (or Adminer at `http://localhost:8081`) with the following credentials:

- Host: localhost
- Port: 3308 (as defined in the .env file)
- Database: cbz_reader
- Username: cbz_user
- Password: cbz_password

For Adminer, the server name to connect to is `database` (the service name in `docker compose.yml`).

### Database Migrations

When making changes to entity classes, you need to create and run migrations:

```sh
# Create a new migration
docker compose exec php bin/console make:migration

# Run migrations
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

### Utility Commands

The application includes several utility commands to help with management and testing:

```sh
# Create an admin user
docker compose exec php bin/console app:create-admin-user admin@example.com password123

# Create a regular user
docker compose exec php bin/console app:create-user user@example.com password123

# Set up upload directories
docker compose exec php bin/console app:setup-upload-directories

# Import comics from a directory
docker compose exec php bin/console app:import-comics /path/to/comics admin@example.com

# Generate sample data for testing
docker compose exec php bin/console app:generate-sample-data --force

# Clean up unused comics and cover images
docker compose exec php bin/console app:cleanup-comics --dry-run

# Test API endpoints (registration and login)
docker compose exec php bin/console app:test-api-endpoints
```

### File Organization

The application organizes uploaded comics and cover images as follows:

- **Comics**: Stored in user-specific directories at `/uploads/comics/{user_id}/{comic_file.cbz}`
- **Cover Images**: Stored in comic-specific directories at `/uploads/comics/covers/{comic_id}/{cover_image.jpg}`

This organization ensures proper separation of user content and makes it easier to manage comics and their associated cover images.

### Environment Variables

The application uses several environment variables for configuration. These are defined in different `.env` files depending on the environment:

#### Core Environment Variables

- `APP_ENV`: The application environment (`dev`, `prod`, etc.)
- `APP_SECRET`: Secret key used for security-related operations
- `DATABASE_URL`: Database connection string
- `CORS_ALLOW_ORIGIN`: CORS configuration for API access

#### Email Configuration

- `MAILER_DSN`: Mail server connection string
- `MAILER_FROM_ADDRESS`: Email address used as the sender
- `MAILER_FROM_NAME`: Name displayed as the sender
- `MAILER_TRANSPORT`: Transport method for emails (`smtp`, `sync`, etc.)

#### Frontend URL Configuration

- `FRONTEND_SCHEME`: Protocol used by the frontend (`http` or `https`)
- `FRONTEND_HOST`: Hostname of the frontend application
- `FRONTEND_PORT`: Port used by the frontend application

#### Development vs. Production

For development, use `.env.local` with settings like:
```
APP_ENV=dev
FRONTEND_SCHEME=http
FRONTEND_HOST=localhost
FRONTEND_PORT=3001
```

For production, create `.env.prod.local` with settings like:
```
APP_ENV=prod
FRONTEND_SCHEME=https
FRONTEND_HOST=comics.yourdomain.com
FRONTEND_PORT=443
```

> **Important**: When deploying to production, make sure to set the correct frontend URL configuration to ensure email verification links and password reset links point to your production site, not localhost.

## Deployment

### Production Deployment

The application uses GitHub Actions for automated deployment when changes are merged into the `main` branch.

#### Current Deployment Process

1. **Trigger**: Deployment occurs automatically when a Pull Request from `develop` to `main` is merged
2. **Frontend Build**: The React frontend is built using Vite
3. **Frontend Deployment**: Built files are deployed via FTP to the production server's `backend/public/` directory
4. **Backend Deployment**: Currently requires manual intervention (see TODO below)

#### GitHub Secrets Required

The following secrets must be configured in your GitHub repository:

- `FTP_SERVER`: Your production server hostname
- `FTP_USERNAME`: FTP username for deployment
- `FTP_PASSWORD`: FTP password for deployment

#### Deployment Safety Features

- **Safe Mode**: The deployment never deletes existing files on the server
- **Protected Directories**: User uploads (`uploads/`) and backend files are never touched
- **Force Upload**: Ensures frontend assets are always updated even if they appear identical

#### Current Limitations & TODO

**Backend Deployment**: Currently, backend code changes require manual deployment. The recommended approach is to SSH into the production server and run:

```bash
cd /path/to/project
git pull origin main
composer install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod
php bin/console doctrine:migrations:migrate --no-interaction
```

**Planned Improvement**: Automate backend deployment via SSH in the GitHub Actions workflow. This would be much more efficient than FTP uploading the entire backend codebase.

#### Deployment Workflow File

The deployment configuration is in `.github/workflows/build-frontend.yml`. This workflow:

- Only triggers on PR merges to `main` (not direct pushes)
- Builds the frontend with production optimizations
- Deploys frontend assets safely without affecting backend files or user data
- Includes comprehensive TODO comments for SSH automation implementation

#### Emergency Recovery

In case of deployment issues, an emergency restore workflow is available at `.github/workflows/emergency-backend-restore.yml` that can be manually triggered to restore critical backend files.

### Development vs Production

- **Development**: Use `docker compose up -d` for local development with hot reload
- **Production**: Deployed via GitHub Actions with optimized builds and proper caching headers

## License

This project is proprietary and confidential.
