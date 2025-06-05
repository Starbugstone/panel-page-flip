/**
 * Session Manager
 * 
 * Handles session management, including:
 * - Session keep-alive functionality
 * - Session status checking
 * - Session expiration handling
 */

// Check if we're in development mode
const isDevelopment = process.env.NODE_ENV === 'development' || 
  window.location.hostname === 'localhost' || 
  window.location.hostname === '127.0.0.1';

// Logger function that only logs in development
const logger = {
  log: (...args) => isDevelopment && console.log(...args),
  warn: (...args) => isDevelopment && console.warn(...args),
  error: (...args) => console.error(...args) // Always log errors
};

class SessionManager {
  constructor() {
    this.keepAliveInterval = null;
    this.sessionCheckInterval = null;
    this.isActive = false;
    this.onSessionExpired = null;
    this.lastActivityTime = Date.now();
    this.sessionEndpoint = '/api/users/me';
    this.checkInProgress = false;
    this.lastCheckTime = 0;
    this.minCheckInterval = 1000; // Minimum 1 second between checks
  }

  /**
   * Start the session manager
   * @param {Object} options Configuration options
   * @param {Function} options.onSessionExpired Callback when session expires
   * @param {number} options.keepAliveIntervalMs Interval for keep-alive pings (default: 4 minutes)
   * @param {number} options.sessionCheckIntervalMs Interval for session checks (default: 1 minute)
   */
  start(options = {}) {
    this.onSessionExpired = options.onSessionExpired;
    const keepAliveIntervalMs = options.keepAliveIntervalMs || 4 * 60 * 1000; // 4 minutes
    const sessionCheckIntervalMs = options.sessionCheckIntervalMs || 60 * 1000; // 1 minute

    // Clear any existing intervals
    this.stop();
    
    // Start keep-alive pings
    this.keepAliveInterval = setInterval(() => {
      this.pingKeepAlive();
    }, keepAliveIntervalMs);
    
    // Start session checks
    this.sessionCheckInterval = setInterval(() => {
      this.checkSession();
    }, sessionCheckIntervalMs);
    
    // Set initial state
    this.isActive = true;
    this.updateLastActivity();
    
    // Add activity listeners
    this.addActivityListeners();
    
    logger.log('Session manager started');
  }

  /**
   * Stop the session manager
   */
  stop() {
    if (this.keepAliveInterval) {
      clearInterval(this.keepAliveInterval);
      this.keepAliveInterval = null;
    }
    
    if (this.sessionCheckInterval) {
      clearInterval(this.sessionCheckInterval);
      this.sessionCheckInterval = null;
    }
    
    this.removeActivityListeners();
    this.isActive = false;
    
    logger.log('Session manager stopped');
  }

  /**
   * Update the last activity timestamp
   */
  updateLastActivity() {
    this.lastActivityTime = Date.now();
  }

  /**
   * Add event listeners for user activity
   */
  addActivityListeners() {
    ['mousedown', 'keydown', 'touchstart', 'scroll'].forEach(eventType => {
      document.addEventListener(eventType, this.handleUserActivity.bind(this), { passive: true });
    });
  }

  /**
   * Remove event listeners for user activity
   */
  removeActivityListeners() {
    ['mousedown', 'keydown', 'touchstart', 'scroll'].forEach(eventType => {
      document.removeEventListener(eventType, this.handleUserActivity.bind(this));
    });
  }

  /**
   * Handle user activity events
   */
  handleUserActivity() {
    this.updateLastActivity();
  }

  /**
   * Send a keep-alive ping to the server
   * @returns {Promise<boolean>} True if successful, false otherwise
   */
  async pingKeepAlive() {
    // Prevent multiple simultaneous pings
    if (this.checkInProgress) {
      logger.log('Keep-alive ping already in progress, skipping');
      return true; // Assume success to prevent cascading failures
    }

    // Implement rate limiting
    const now = Date.now();
    if (now - this.lastCheckTime < this.minCheckInterval) {
      logger.log('Keep-alive ping too frequent, skipping');
      return true; // Assume success to prevent cascading failures
    }

    try {
      this.checkInProgress = true;
      this.lastCheckTime = now;
      logger.log('Sending session keep-alive ping');
      
      // Get CSRF token from cookies
      const csrfToken = this.getCsrfToken();
      logger.log('Using CSRF token:', csrfToken ? 'Token present' : 'No token found');
      
      // Create headers with CSRF token
      const headers = {
        'Content-Type': 'application/json',
      };
      
      // Only add CSRF token if it exists
      if (csrfToken) {
        headers['X-XSRF-TOKEN'] = csrfToken;
      }
      
      const response = await fetch(this.sessionEndpoint, {
        method: 'POST',  // Using POST to indicate this is a keep-alive ping
        headers: headers,
        credentials: 'include',
      });
      
      if (!response.ok) {
        logger.error('Keep-alive ping failed with status:', response.status);
        // Try to get error details
        try {
          const errorData = await response.json();
          logger.error('Error details:', errorData);
        } catch (e) {
          // Ignore if we can't parse the error response
        }
      }
      
      return response.ok;
    } catch (error) {
      logger.error('Keep-alive ping failed:', error);
      return false;
    } finally {
      this.checkInProgress = false;
    }
  }

  /**
   * Check if the session is still valid
   * @param {boolean} triggerExpiration Whether to trigger the session expiration callback if session is invalid
   * @returns {Promise<boolean>} True if session is valid, false otherwise
   */
  async checkSession(triggerExpiration = true) {
    // Prevent multiple simultaneous checks
    if (this.checkInProgress) {
      logger.log('Session check already in progress, skipping');
      return true; // Assume success to prevent cascading failures
    }

    // Implement rate limiting
    const now = Date.now();
    if (now - this.lastCheckTime < this.minCheckInterval) {
      logger.log('Session check too frequent, skipping');
      return true; // Assume success to prevent cascading failures
    }

    try {
      this.checkInProgress = true;
      this.lastCheckTime = now;
      
      const response = await fetch(this.sessionEndpoint, {
        method: 'GET',  // Using GET for session checks
        credentials: 'include',
      });
      
      if (!response.ok) {
        if (triggerExpiration && this.onSessionExpired && this.isActive) {
          // Only trigger once
          const callback = this.onSessionExpired;
          this.onSessionExpired = null;
          callback();
        }
        return false;
      }
      
      return true;
    } catch (error) {
      logger.error('Session check error:', error);
      if (triggerExpiration && this.onSessionExpired && this.isActive) {
        // Only trigger once
        const callback = this.onSessionExpired;
        this.onSessionExpired = null;
        callback();
      }
      return false;
    } finally {
      this.checkInProgress = false;
    }
  }

  /**
   * Force an immediate session check
   * @returns {Promise<boolean>} True if session is valid, false otherwise
   */
  async forceSessionCheck() {
    // If a check is already in progress, don't force another one
    if (this.checkInProgress) {
      logger.log('Session check already in progress, not forcing another');
      return true; // Assume success to prevent cascading failures
    }
    
    logger.log('Forcing session check');
    return await this.checkSession(true);
  }

  /**
   * Get CSRF token from cookies
   * @returns {string} CSRF token
   */
  getCsrfToken() {
    try {
      // More robust cookie parsing
      const cookies = document.cookie.split(';').reduce((acc, cookie) => {
        if (!cookie.trim()) return acc;
        
        const parts = cookie.trim().split('=');
        if (parts.length < 2) return acc;
        
        const key = parts[0].trim();
        const value = parts.slice(1).join('='); // Handle values that might contain =
        
        acc[key] = decodeURIComponent(value);
        return acc;
      }, {});
      
      // Look for XSRF-TOKEN or CSRF-TOKEN
      const token = cookies['XSRF-TOKEN'] || cookies['CSRF-TOKEN'] || '';
      
      if (!token) {
        logger.warn('No CSRF token found in cookies');
      }
      
      return token;
    } catch (error) {
      logger.error('Error extracting CSRF token:', error);
      return '';
    }
  }
  
  /**
   * Force an immediate session check
   * @returns {Promise<boolean>} True if session is valid, false otherwise
   */
  async forceSessionCheck() {
    // If a check is already in progress, don't force another one
    if (this.checkInProgress) {
      logger.log('Session check already in progress, not forcing another');
      return true; // Assume success to prevent cascading failures
    }
    
    logger.log('Forcing session check');
    return await this.checkSession(true);
  }
}

// Create a singleton instance
const sessionManager = new SessionManager();

export default sessionManager;
