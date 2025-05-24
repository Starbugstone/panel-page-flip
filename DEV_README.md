# CBZ Comic Reader - Developer Documentation

## Project Overview

CBZ Comic Reader is a web application that allows users to read comic books in CBZ format. The application features a secure login system, a comic selection interface, and a reading progress tracker that remembers where you left off.

This document provides detailed information for developers working on the project, including current implementation status, architecture details, and next steps.

## Current Implementation Status

### Backend (Symfony)

#### ✅ User Authentication System
- **JSON Login**: Implemented in `security.yaml` with proper routes and handlers
- **Registration**: Implemented in `RegistrationController.php`
- **Password Reset**: Implemented in `ResetPasswordController.php` with email notifications
- **User Entity**: Defined in `User.php` with proper properties and relationships
- **Security**: Access control rules defined to secure API endpoints

#### ✅ Comic Management System
- **Comic Entity**: Defined in `Comic.php` with properties for title, file path, cover image, etc.
- **Comic Controller**: Implemented in `ComicController.php` with endpoints for CRUD operations. Includes refined permission checks for operations like comic deletion (owner/admin only).
- **File Storage**: Comics are stored in user-specific directories at `/uploads/comics/{user_id}/{comic_file.cbz}`
- **Cover Images**: Stored in comic-specific directories at `/uploads/comics/covers/{comic_id}/{cover_image.jpg}`
- **Chunked Upload**: Implemented chunked file upload system to handle large comic files (1MB chunks)
  - Initialization endpoint: `/api/comics/upload/init`
  - Chunk upload endpoint: `/api/comics/upload/chunk`
  - Completion endpoint: `/api/comics/upload/complete`

#### ✅ Reading Progress Tracking
- **ComicReadingProgress Entity**: Defined in `ComicReadingProgress.php` to track user reading progress
- **API Endpoints**: Available for saving and retrieving reading progress

#### ✅ Tag System
- **Tag Entity**: Defined in `Tag.php` for categorizing comics
- **API Endpoints**: Available for managing tags and associating them with comics

#### ✅ Utility Commands
- **CreateUserCommand**: Creates regular users (`app:create-user`)
- **CreateAdminUserCommand**: Creates admin users (`app:create-admin-user`)
- **ImportComicsCommand**: Imports comics from a directory (`app:import-comics`)
- **CleanupComicsCommand**: Cleans up orphaned comic files and cover images (`app:cleanup-comics`)
- **SetupUploadDirectoriesCommand**: Sets up necessary directories for uploads (`app:setup-upload-directories`)
- **GenerateSampleDataCommand**: Generates sample data for testing (`app:generate-sample-data`)
- **TestApiEndpointsCommand**: Tests API endpoints for registration and login (`app:test-api-endpoints`)

### Frontend (React)

#### ✅ User Interface
- **Landing Page**: Implemented in `Landing.jsx` with project introduction
- **Authentication Pages**: Login and registration implemented in `Login.jsx`
- **Password Reset**: Forgot password and reset password pages implemented in `ForgotPassword.jsx` and `ResetPassword.jsx`
- **Dashboard**: Comic library view implemented in `Dashboard.jsx`

#### ✅ Comic Reader
- **Reading Interface**: Core reading functionality implemented in `ComicReader.jsx`
- **Advanced Caching System**: Implemented a robust caching system that stores comic pages as data URLs to prevent unnecessary network requests
- **Optimized Page Loading**: Uses a priority queue system to load pages in order of likelihood to be viewed next
- **Memory Management**: Automatically cleans up cached pages outside the viewing window (±5 pages) to prevent memory overflow
- **Network Optimization**: Prevents duplicate network requests by tracking in-progress loads
- **Responsive UI**: Immediately displays cached pages while loading new ones in the background
- **Debug Panel**: Provides real-time visibility into cache state and loading processes

#### ✅ Admin Interface
- **Admin Dashboard**: Implemented in `AdminDashboard.jsx`, now includes a loading indicator during user authentication.
- **Admin Users Management**: Enhanced UI in `AdminUsersList.jsx` for managing user roles (e.g., ensuring `ROLE_USER` persistence, clearer role assignment).
- **Admin Comics List**: Improved tag display in `AdminComicsList.jsx` to correctly handle various tag data formats.
- **Admin Tags List**: UI refinements in `AdminTagsList.jsx` for tag creation and editing dialogs.

#### ✅ Comic Upload
- **Upload Comic**: Comic upload interface implemented in `UploadComic.jsx` with chunked upload support, progress tracking, and tag management

The frontend is built with:
- React with JavaScript (converted from TypeScript)
- Vite for fast development and building
- shadcn-ui components
- Tailwind CSS for styling
- React Router for navigation
- **Theme Persistence**: Switched from `localStorage` to client-side cookies for storing theme preferences (light/dark mode), managed by `ThemeProvider.jsx` and a new utility module `frontend/src/lib/cookies.js`. Includes a migration step from `localStorage`.
- **Authentication Hook (`use-auth.jsx`)**: The `checkAuth` function updated to use `/api/users/me` for fetching comprehensive authenticated user details, including roles.
- **Cookie Utility (`frontend/src/lib/cookies.js`)**: New module added with helper functions for managing browser cookies.

## Architecture Details

### Directory Structure

```
./
├── frontend/           # React frontend application (to be implemented)
│   ├── src/            # React source code
│   │   ├── components/ # UI components
│   │   ├── hooks/      # Custom React hooks including authentication
│   │   ├── pages/      # Page components
│   │   └── lib/        # Utility functions
│   ├── public/         # Static assets for frontend
│   └── package.json    # Frontend dependencies
├── backend/            # Symfony backend application
│   ├── src/            # Symfony source code
│   │   ├── Controller/ # API controllers
│   │   ├── Entity/     # Database entities
│   │   ├── Security/   # Authentication handlers
│   │   └── Command/    # CLI commands
│   └── ...             # Other Symfony files and folders
├── docker/             # Docker configuration files
├── docker-compose.yml  # Docker Compose configuration for all services
└── .env                # Main environment variables file
```

### Database Schema

#### User Entity
- `id`: Primary key
- `email`: User's email (unique)
- `password`: Hashed password
- `roles`: Array of user roles (ROLE_USER, ROLE_ADMIN)
- `name`: User's name (optional)
- `createdAt`: Timestamp of user creation
- `updatedAt`: Timestamp of last update
- Relationships:
  - One-to-Many with Comic (owner)
  - One-to-Many with ComicReadingProgress
  - One-to-Many with Tag (creator)

#### Comic Entity
- `id`: Primary key
- `title`: Comic title
- `filePath`: Path to the CBZ file
- `coverImagePath`: Path to the cover image
- `pageCount`: Number of pages in the comic
- `description`: Comic description (optional)
- `createdAt`: Timestamp of comic creation
- `updatedAt`: Timestamp of last update
- Relationships:
  - Many-to-One with User (owner)
  - One-to-Many with ComicReadingProgress
  - Many-to-Many with Tag

#### ComicReadingProgress Entity
- `id`: Primary key
- `currentPage`: Current page number
- `lastReadAt`: Timestamp of last reading
- Relationships:
  - Many-to-One with User
  - Many-to-One with Comic

#### Tag Entity
- `id`: Primary key
- `name`: Tag name
- `createdAt`: Timestamp of tag creation
- Relationships:
  - Many-to-One with User (creator)
  - Many-to-Many with Comic

### API Endpoints

#### Authentication
- `POST /api/login` - Login with email and password
- `POST /api/register` - Register a new user
- `POST /api/logout` - Logout the current user
- `GET /api/login_check` - Check if the user is authenticated
- `GET /api/users/me` - Get the current authenticated user's information, including roles. Primarily used by the frontend to check authentication status and retrieve user details.
- `POST /api/forgot-password` - Request a password reset email
- `GET /api/reset-password/validate/{token}` - Validate a password reset token
- `POST /api/reset-password/reset/{token}` - Reset password with a valid token

#### Comics
- `GET /api/comics` - Get all comics for the current user
- `GET /api/comics/{id}` - Get a specific comic by ID
- `POST /api/comics` - Upload a new comic (multipart/form-data with file, title, and optional fields)
- `PUT/PATCH /api/comics/{id}` - Update a comic's information
- `DELETE /api/comics/{id}` - Delete a comic
- `GET /api/comics/{id}/pages/{page}` - Get a specific page from a comic
- `POST /api/comics/{id}/progress` - Update reading progress for a comic

#### Tags
- `GET /api/tags` - Get all tags
- `POST /api/tags` - Create a new tag
- `PUT/PATCH /api/tags/{id}` - Update a tag
- `DELETE /api/tags/{id}` - Delete a tag

#### User Management (Admin only)
- `GET /api/users` - Get all users (admin only)
- `GET /api/users/{id}` - Get a specific user
- `PUT/PATCH /api/users/{id}` - Update a user
- `DELETE /api/users/{id}` - Delete a user

### File Storage Organization

#### Comics
Comics are stored in user-specific directories to ensure proper separation of user content:
```
/uploads/comics/{user_id}/{comic_file.cbz}
```

For example:
- `/uploads/comics/1/my_comic.cbz` - Comic owned by user with ID 1
- `/uploads/comics/2/another_comic.cbz` - Comic owned by user with ID 2

#### Cover Images
Cover images are stored in comic-specific directories for better organization:
```
/uploads/comics/covers/{comic_id}/{cover_image.jpg}
```

For example:
- `/uploads/comics/covers/1/cover.jpg` - Cover image for comic with ID 1
- `/uploads/comics/covers/2/cover.jpg` - Cover image for comic with ID 2

### Email Testing with Mailpit

The application uses Mailpit for email testing during development. Mailpit captures all outgoing emails and provides a web interface to view them without actually sending them to real email addresses.

- **SMTP Server**: Available at `mailpit:1025` inside the Docker network
- **Web UI**: Available at http://localhost:8025 for viewing captured emails
- **Usage**: When testing the password reset functionality, check the Mailpit UI to see the reset emails

#### Email Delivery Configuration

Symphony's Messenger component is used for handling emails. By default, Symfony would queue emails for asynchronous delivery, but we've modified this for development to make emails send immediately:

1. **Development Configuration** (current setup):
   - In `config/packages/messenger.yaml`, the email routing is commented out:
     ```yaml
     routing:
         # Comment out this line to send emails synchronously
         # Symfony\Component\Mailer\Messenger\SendEmailMessage: async
         Symfony\Component\Notifier\Message\ChatMessage: async
         Symfony\Component\Notifier\Message\SmsMessage: async
     ```
   - This makes all emails send immediately (synchronously) without requiring a message consumer
   - Emails appear in Mailpit right away
   - This is ideal for development and testing

2. **Production Configuration** (to be implemented):
   - For production, uncomment the email routing line:
     ```yaml
     routing:
         Symfony\Component\Mailer\Messenger\SendEmailMessage: async
         Symfony\Component\Notifier\Message\ChatMessage: async
         Symfony\Component\Notifier\Message\SmsMessage: async
     ```
   - This queues emails in the database (`messenger_messages` table)
   - You must run a Messenger consumer to process the queue:
     ```bash
     # Run a consumer as a background service
     php bin/console messenger:consume async
     ```
   - In production, set up a systemd service or supervisor process to keep the consumer running
   - This approach is more resilient and prevents email sending from blocking web requests

#### Debugging Email Issues

If emails aren't appearing in Mailpit:

1. Check if emails are being queued in the database:
   ```bash
   docker compose exec php bin/console messenger:stats
   ```

2. If there are queued messages but no consumer is running:
   ```bash
   docker compose exec php bin/console messenger:consume async --time-limit=3600
   ```

3. Check for failed messages:
   ```bash
   docker compose exec php bin/console messenger:failed:show
   ```

4. Test email sending directly:
   ```bash
   docker compose exec php bin/console app:test-mail --to=test@example.com
   ```

### Testing the Current Implementation

### Test Users
Test users are created for development and testing purposes. Their credentials are stored in `passwords.txt` (which is in `.gitignore`):
- Admin user: `testadmin@example.com` with password `AdminPass123!`
- Regular user: `testuser1@example.com` with password `UserPass123!`
- Regular user: `testuser2@example.com` with password `UserPass123!`

### Testing Commands
You can test the current implementation using the following commands:

```sh
# Test API endpoints (registration and login)
docker compose exec php bin/console app:test-api-endpoints

# Generate sample data for testing
docker compose exec php bin/console app:generate-sample-data --force

# Import comics from a directory
docker compose exec php bin/console app:import-comics /path/to/comics testuser1@example.com

# Clean up unused comics and cover images (dry run)
docker compose exec php bin/console app:cleanup-comics --dry-run
```

### Manual API Testing
You can also test the API endpoints manually using tools like Postman or curl:

```sh
# Login
curl -X POST http://localhost:8080/api/login -H "Content-Type: application/json" -d '{"email":"testadmin@example.com","password":"AdminPass123!"}'

# Register
curl -X POST http://localhost:8080/api/register -H "Content-Type: application/json" -d '{"email":"newuser@example.com","password":"NewPassword123!"}'

# Get Comics (requires authentication cookie from login)
curl -X GET http://localhost:8080/api/comics -H "Content-Type: application/json" -b cookies.txt

## Recent Updates

### Comic Reader Caching Improvements

The comic reader component has been significantly optimized to improve performance and user experience:

1. **Data URL Caching**: Comic pages are now stored as data URLs in memory to prevent unnecessary network requests
   - Pages are converted to base64-encoded data URLs when loaded
   - This prevents the browser from making new HTTP requests for previously loaded images
   - Fallback to Image object caching if data URL conversion fails

2. **Loading State Tracking**:
   - Added a loading tracker to prevent duplicate requests for the same page
   - Each page load is tracked with a Promise to ensure we don't start multiple loads for the same page

3. **Memory Management**:
   - Cache window limited to ±5 pages around the current page
   - Pages outside this window are automatically removed from cache
   - This prevents memory issues when reading large comics

4. **Optimized Navigation**:
   - Page state is updated immediately when navigating to a cached page
   - No loading indicator shown for cached pages, creating a seamless experience
   - Priority loading queue ensures the most likely-to-be-viewed pages load first

5. **Debug Information**:
   - Added comprehensive debug panel to monitor cache state
   - Removed console logs to clean up browser console

### Frontend Improvements

#### 1. Authentication Pages
- ✅ **Login Page**: Implemented with email and password fields
- ✅ **Registration Page**: Implemented with email, password fields
- ✅ **Password Reset Flow**: Fully implemented with:
  - Forgot password request form
  - Email delivery with frontend reset links
  - Token validation
  - Password reset form
  - Success notifications and redirects
  - Security notification emails
- ✅ **Authentication State Management**: Implemented using React Context

#### 2. Comic Library Interface
- **Comic List Page**: Grid or list view of user's comics with cover images
- **Comic Details Page**: Detailed view of a comic with metadata and reading progress
- **Upload Comic Form**: Form for uploading new comics with title and tags

#### 3. Comic Reader Interface
- **Reader Page**: Page for reading comics with navigation controls
- **Page Navigation**: Controls for moving between pages
- **Reading Progress**: Automatic saving of reading progress
- **Fullscreen Mode**: Toggle for fullscreen reading

#### 4. Tag Management
- **Tag List**: Interface for viewing and managing tags
- **Add/Edit Tag Form**: Form for creating and editing tags
- **Tag Assignment**: Interface for assigning tags to comics

#### 5. User Profile
- **Profile Page**: Page for viewing and editing user profile
- ✅ **Password Change**: Implemented through password reset functionality

#### 6. Dark Mode
- **Theme Toggle**: Button for switching between light and dark themes
- **Theme Implementation**: CSS variables or Tailwind dark mode

### Backend Enhancements

#### 1. CBZ Reader Implementation
- Implement a proper CBZ reader to extract and process comic pages
- Improve cover image extraction to always use the first page
- Optimize image processing for better performance

#### 2. Search and Filtering
- Implement search functionality for comics
- Add filtering by tags, upload date, reading progress, etc.

#### 3. Performance Optimizations
- Implement caching for frequently accessed data
- Optimize database queries for better performance
- Add pagination for large collections

### Email System Implementation

#### ✅ Email Configuration
- **Mailpit Integration**: Configured for email testing in development
- **Email Templates**: HTML email templates implemented for:
  - Password reset requests
  - Password change notifications
- **Synchronous Delivery**: Configured for immediate delivery in development
- **Asynchronous Support**: Ready for production with Messenger component

#### ✅ Email Testing with Mailpit
- **SMTP Server**: Available at `mailpit:1025` inside the Docker network
- **Web UI**: Available at http://localhost:8025 for viewing captured emails
- **Test Command**: `app:test-mail` command available for testing email delivery
- **Debug Tools**: Messenger commands for diagnosing email delivery issues:
  ```bash
  # Check message queue status
  docker compose exec php bin/console messenger:stats
  
  # Process queued messages (for async mode)
  docker compose exec php bin/console messenger:consume async
  
  # Check for failed messages
  docker compose exec php bin/console messenger:failed:show
  ```

## Getting Started (Development)

### Prerequisites
- Docker and Docker Compose
- Git

### Setup
1. Clone the repository
2. Start the Docker containers:
   ```sh
   docker compose up -d
   ```
3. Set up the upload directories:
   ```sh
   docker compose exec php bin/console app:setup-upload-directories
   ```
4. Create test users:
   ```sh
   docker compose exec php bin/console app:create-admin-user testadmin@example.com AdminPass123!
   docker compose exec php bin/console app:create-user testuser1@example.com UserPass123!
   ```
5. Import comics (optional):
   ```sh
   docker compose exec php bin/console app:import-comics /path/to/comics testuser1@example.com
   ```

### Frontend Development with Live Reload

The frontend React application is now served directly by a dedicated Vite development server running inside the `frontend_dev` Docker service. This provides Hot Module Replacement (HMR) for a significantly improved development experience.

**Key Details:**
*   **Access URL:** To view and interact with the live-reloading frontend, open your browser to **`http://localhost:3001`**.
*   **Live Reload / HMR:** When you make changes to files within the `./frontend/src` directory on your host machine, the Vite server inside the `frontend_dev` container will automatically detect these changes, rebuild the necessary parts of the application, and push updates to your browser, often without a full page refresh.
*   **Automatic Dependency Installation:** The `frontend_dev` service automatically runs `npm install` when it starts, ensuring all frontend dependencies are up-to-date based on `package.json` and `package-lock.json`. You generally do not need to run `npm install` manually in the `frontend` directory unless you are specifically managing dependencies before restarting the Docker services.
*   **Role of `nginx` service (Port 8080):** The original `nginx` service (accessible at `http://localhost:8080` or your `${NGINX_PORT}`) continues to be responsible for:
    *   Proxying API requests (e.g., `/api/...`) to the backend PHP service. The Vite dev server on port 3001 is configured to route its API calls to this Nginx service.
    *   Serving a static build of the frontend if you were to build it for production (e.g., via `npm run build` results). For development, port 3001 is primary.

To start developing the frontend:
1.  Ensure all Docker services are running with `docker compose up -d`.
2.  Navigate to `http://localhost:3001` in your browser.
3.  Begin editing files in the `./frontend` directory.

## Recommended Next Steps

1. **Start Frontend Implementation**:
   - Create the comic library browsing interface

2. **Implement CBZ Reader**:
   - Develop the comic reading interface
   - Implement page navigation controls
   - Connect with the backend for reading progress tracking

3. **Add Tag Management**:
   - Create the tag management interface
   - Implement tag assignment to comics
   - Add filtering by tags

4. **Implement Search and Filtering**:
   - Add search functionality
   - Implement filtering options
   - Create a user-friendly search interface

5. **Add User Profile Management**:
   - Create the profile page
   - Implement password change functionality
   - Add user preferences

6. **Implement Dark Mode**:
   - Add theme toggle
   - Implement dark mode styles
   - Save user theme preference

## Known Issues and Considerations

1. **CBZ Reader Implementation**: The current implementation extracts the first image found in the CBZ file as the cover image. A proper CBZ reader should be implemented to always use the first page as the cover.

2. **File Storage**: The current implementation stores files in the filesystem. For production, consider using a more robust storage solution like AWS S3 or similar.

3. **Authentication**: The current implementation uses stateful authentication with sessions. For a more modern approach, consider implementing JWT-based authentication.

4. **Error Handling**: The current implementation has basic error handling. More comprehensive error handling should be implemented for production.

5. **Testing**: The current implementation has minimal testing. More comprehensive testing should be added for production.

## Troubleshooting

### Docker and Windows Line Endings

When running the project on Windows, you may encounter issues with line endings in shell scripts and PHP files. This is because Windows uses CRLF (\r\n) line endings, while Linux uses LF (\n) line endings.

**Symptoms:**
- Error messages like `/usr/bin/env: 'php\r': No such file or directory`
- Scripts failing to execute with `not found` errors

**Solutions:**
1. Fix line endings in the console script:
   ```sh
   docker compose exec php sed -i 's/\r$//' /var/www/html/bin/console
   ```

2. Configure Git to handle line endings properly:
   ```sh
   git config --global core.autocrlf input
   ```

### Hot Reload Issues with Docker on Windows

The frontend development server may not detect file changes properly when running in Docker on Windows.

**Symptoms:**
- Changes to frontend files are not reflected in the browser
- No file change detection messages in the frontend_dev container logs

**Solutions:**
Update the `frontend_dev` service in `docker-compose.yml` with the following configuration:

```yaml
frontend_dev:
  image: node:${NODE_VERSION:-18}-alpine
  container_name: ${COMPOSE_PROJECT_NAME:-cbz_reader}_frontend_dev
  volumes:
    - ./frontend:/app
    - /app/node_modules
  working_dir: /app
  command: sh -c "npm install && npm run dev -- --host 0.0.0.0 --force"
  ports:
    - "3001:3000"
  networks:
    - app_network
  depends_on:
    - nginx
  environment:
    - NODE_ENV=development
    - CHOKIDAR_USEPOLLING=true
    - WATCHPACK_POLLING=true
```

Key changes:
- Added volume mount for `/app/node_modules` to prevent it from being overwritten
- Enabled file polling with `CHOKIDAR_USEPOLLING` and `WATCHPACK_POLLING` environment variables
- Added `--host 0.0.0.0` and `--force` flags to the Vite dev command

## Conclusion

The CBZ Comic Reader project has a solid backend foundation with user authentication, comic management, and reading progress tracking. The next major step is to implement the frontend to provide a user-friendly interface for reading comics.

By following the recommended next steps, you can complete the project and create a fully functional comic reader application.
