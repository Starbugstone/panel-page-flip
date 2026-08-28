/**
 * Session Manager
 * 
 * Handles session management, including:
 * - Session keep-alive functionality
 * - Session status checking
 * - Session expiration handling
 */

import { api } from '@/lib/api';
import { logger } from '@/lib/logger';

class SessionManager {
  constructor() {
    this.keepAliveInterval = null;
    this.sessionCheckInterval = null;
    this.isActive = false;
    this.onSessionExpired = null;
    this.lastActivityTime = Date.now();
    this.sessionEndpoint = '/api/me';
    this.checkInProgress = false;
    this.lastCheckTime = 0;
    this.minCheckInterval = 1000; // Minimum 1 second between checks
    this.boundActivityHandler = this.handleUserActivity.bind(this);
    this.consecutiveFailures = 0;
    this.maxConsecutiveFailures = 2;
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
      document.addEventListener(eventType, this.boundActivityHandler, { passive: true });
    });
  }

  /**
   * Remove event listeners for user activity
   */
  removeActivityListeners() {
    ['mousedown', 'keydown', 'touchstart', 'scroll'].forEach(eventType => {
      document.removeEventListener(eventType, this.boundActivityHandler);
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
      
      await api.post(this.sessionEndpoint, {}, { notifyUnauthorized: false });
      return true;
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
      
      const data = await api.get(this.sessionEndpoint, { notifyUnauthorized: false });
      if (!data?.user) {
        if (triggerExpiration && this.onSessionExpired && this.isActive) {
          const callback = this.onSessionExpired;
          this.onSessionExpired = null;
          callback();
        }
        return false;
      }
      this.consecutiveFailures = 0;
      return true;
    } catch (error) {
      logger.warn('Session check failed:', error.message);
      if (triggerExpiration && this.onSessionExpired && this.isActive) {
        this.consecutiveFailures++;
        if (error.status !== 401 && this.consecutiveFailures < this.maxConsecutiveFailures) {
          return false;
        }
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

  // CSRF tokens are read by lib/csrf.js, which lib/api.js applies to every
  // mutating request. Do not add a second implementation here.
}

// Create a singleton instance
const sessionManager = new SessionManager();

export default sessionManager;
