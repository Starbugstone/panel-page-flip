# Developer Guide (English)

This document summarizes the structure and main components of the CBZ Comic Reader project for new contributors.

## Project Structure

```
./
├── frontend/           # React application
│   ├── src/
│   │   ├── components/ # Reusable UI components
│   │   ├── hooks/      # Custom hooks (authentication, etc.)
│   │   ├── pages/      # Page-level components
│   │   └── lib/        # Utility functions
├── backend/            # Symfony backend
│   ├── src/
│   │   ├── Controller/ # API controllers
│   │   ├── Entity/     # Doctrine entities
│   │   ├── Security/   # Auth handlers
│   │   └── Command/    # CLI commands
├── docker/             # Docker configs
├── docker-compose.yml  # Compose setup
└── .env                # Global environment variables
```

## Key Features
- User authentication with email verification
- Comic library with reading progress tracking
- Large file uploads through chunked transfer
- Dark mode, custom tags and comic sharing
- Dropbox integration with automatic sync

## Environment
Configuration uses several `.env` files:
- `.env` at the project root for Docker
- `backend/.env` and `backend/.env.local` for Symfony
- `frontend/.env` for the React application (if needed)

Dropbox integration requires API keys and folder settings which are shown in the root `README.md`.

## Development Overview
See `DEV_README.md` for a comprehensive breakdown of implementation details. Notable areas include:
- **Authentication, comics management, tags, and sharing logic** in the backend
- **Advanced caching** and **page loading** logic inside `frontend/src/pages/ComicReader.jsx`

## Getting Started
Run the project locally using Docker Compose:

```bash
docker compose up -d
```

The application will be available at <http://localhost:8080>.

---
This guide is intended for quick orientation. For deeper technical information, consult `DEV_README.md` and the rest of the documentation.
