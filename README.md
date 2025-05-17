# CBZ Comic Reader

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
- **Comic Library**: Browse and select from your collection of comics
- **Reading Progress**: Automatically saves your reading position
- **CBZ Format Support**: Read comics in the popular CBZ archive format
- **Responsive Design**: Optimized for both desktop and mobile devices
- **Dark Mode**: Toggle between light and dark themes for comfortable reading

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
- `GET /api/users/me` - Get the current user's information

### Comics

- `GET /api/comics` - Get all comics for the current user
- `GET /api/comics/{id}` - Get a specific comic by ID
- `POST /api/comics` - Upload a new comic (multipart/form-data with file, title, and optional fields)
- `PUT/PATCH /api/comics/{id}` - Update a comic's information
- `DELETE /api/comics/{id}` - Delete a comic
- `GET /api/comics/{id}/pages/{page}` - Get a specific page from a comic
- `POST /api/comics/{id}/progress` - Update reading progress for a comic

### Tags

- `GET /api/tags` - Get all tags
- `POST /api/tags` - Create a new tag
- `PUT/PATCH /api/tags/{id}` - Update a tag
- `DELETE /api/tags/{id}` - Delete a tag

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
docker-compose up -d --build

# The application (frontend and backend API) will be available at http://localhost:8080
# API endpoints are generally prefixed with /api/
# To stop the services:
# docker-compose down
```

The first time you run the containers:
- The `php` service's setup script will automatically install a new Symfony project in the `backend/` directory, install required Symfony packages, and configure the database connection if not already present.
- The `nginx` service will build the React frontend application (from the `frontend/` directory) and configure Nginx to serve it and proxy API calls to the PHP backend.

## Environment Configuration

The project uses several environment files:

- `.env` - Main environment file in the project root with Docker configuration (ports, service names, database credentials, etc.)
- `frontend/.env` - Vite/React frontend specific environment variables (if any, loaded by Vite)
- `backend/.env` - Symfony backend environment variables 
- `backend/.env.local` - Local overrides for Symfony backend environment variables

You can customize the ports and other settings by editing the `.env` file in the project root:

```dotenv
# Example from .env
# Ports
NGINX_PORT=8080     # Application access port
MYSQL_PORT=3308     # Host port mapped to MySQL container
ADMINER_PORT=8081   # Host port mapped to Adminer container
```

## Development Workflow

### Frontend Development

The frontend code is in the `frontend/` directory (specifically `frontend/src/`). 
Since the frontend is now built into the Nginx container, changes to the frontend code require rebuilding the `nginx` image:
```sh
docker-compose up -d --build nginx
```
For a more rapid development experience with Hot Module Replacement (HMR), you might consider running the Vite development server locally outside of Docker for frontend work, and have it proxy API requests to the Dockerized backend.

### Backend Development

The backend code is in the `backend/` directory. After making changes to the Symfony code, you may need to clear the cache:

```sh
docker-compose exec php bin/console cache:clear
```

### Authentication System

The application features a complete authentication system:

- **API Endpoints**:
  - `/api/login` - Login endpoint (POST)
  - `/api/register` - Registration endpoint (POST)
  - `/api/logout` - Logout endpoint (POST)
  - `/api/login_check` - Check authentication status (GET)

- **User Management**:
  - Create new users with the registration form
  - Manage users with the command line tool:
  ```sh
  docker-compose exec php bin/console app:create-user email@example.com password
  ```

### Database Access

You can access the MySQL database using your preferred database client (or Adminer at `http://localhost:8081`) with the following credentials:

- Host: localhost
- Port: 3308 (as defined in the .env file)
- Database: cbz_reader
- Username: cbz_user
- Password: cbz_password

For Adminer, the server name to connect to is `database` (the service name in `docker-compose.yml`).

### Database Migrations

When making changes to entity classes, you need to create and run migrations:

```sh
# Create a new migration
docker-compose exec php bin/console make:migration

# Run migrations
docker-compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

### Utility Commands

The application includes several utility commands to help with management and testing:

```sh
# Create an admin user
docker-compose exec php bin/console app:create-admin-user admin@example.com password123

# Create a regular user
docker-compose exec php bin/console app:create-user user@example.com password123

# Set up upload directories
docker-compose exec php bin/console app:setup-upload-directories

# Import comics from a directory
docker-compose exec php bin/console app:import-comics /path/to/comics admin@example.com

# Generate sample data for testing
docker-compose exec php bin/console app:generate-sample-data --force

# Clean up unused comics and cover images
docker-compose exec php bin/console app:cleanup-comics --dry-run

# Test API endpoints (registration and login)
docker-compose exec php bin/console app:test-api-endpoints
```

### File Organization

The application organizes uploaded comics and cover images as follows:

- **Comics**: Stored in user-specific directories at `/uploads/comics/{user_id}/{comic_file.cbz}`
- **Cover Images**: Stored in comic-specific directories at `/uploads/comics/covers/{comic_id}/{cover_image.jpg}`

This organization ensures proper separation of user content and makes it easier to manage comics and their associated cover images.

## License

This project is proprietary and confidential.
