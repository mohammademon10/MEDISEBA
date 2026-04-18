/**
 * MediSeba - Authentication Module
 * 
 * Handles user authentication state and token management
 */

// Resolve a page path relative to the application root.
// APP_BASE_PATH is defined in api.js and loaded before this file.
function resolvePagePath(relativePath) {
    const base = (typeof APP_BASE_PATH !== 'undefined' ? APP_BASE_PATH : '') || '';
    return `${base}/${relativePath}`.replace(/\/{2,}/g, '/');
}

const AUTH_TOKEN_KEY = 'mediseba_token';
const AUTH_USER_KEY = 'mediseba_user';

const Auth = {
    // Get auth token
    getToken() {
        return localStorage.getItem(AUTH_TOKEN_KEY);
    },
    
    // Set auth token
    setToken(token) {
        localStorage.setItem(AUTH_TOKEN_KEY, token);
    },
    
    // Remove auth token
    removeToken() {
        localStorage.removeItem(AUTH_TOKEN_KEY);
    },
    
    // Get user data
    getUser() {
        const user = localStorage.getItem(AUTH_USER_KEY);
        return user ? JSON.parse(user) : null;
    },
    
    // Set user data
    setUser(user) {
        localStorage.setItem(AUTH_USER_KEY, JSON.stringify(user));
    },
    
    // Remove user data
    removeUser() {
        localStorage.removeItem(AUTH_USER_KEY);
    },
    
    // Check if user is logged in
    isLoggedIn() {
        return !!this.getToken();
    },
    
    // Check if user has specific role
    hasRole(role) {
        const user = this.getUser();
        if (!user) return false;
        
        if (Array.isArray(role)) {
            return role.includes(user.role);
        }
        
        return user.role === role;
    },
    
    // Get user role
    getRole() {
        const user = this.getUser();
        return user ? user.role : null;
    },

    // Get dashboard URL for current user
    getDashboardUrl() {
        const role = this.getRole();
        if (role === 'doctor') return resolvePagePath('doctor/doctor-dashboard.html');
        if (role === 'admin') return resolvePagePath('admin/admin-dashboard.html');
        return resolvePagePath('patient/dashboard.html');
    },

    // Get profile URL for current user
    getProfileUrl() {
        const role = this.getRole();
        if (role === 'doctor') return resolvePagePath('doctor/doctor-my-profile.html');
        if (role === 'admin') return resolvePagePath('admin/admin-settings.html');
        return resolvePagePath('patient/profile.html');
    },
    
    // Logout user
    logout() {
        const role = this.getRole();
        this.removeToken();
        this.removeUser();
        if (role === 'admin') {
            window.location.href = resolvePagePath('auth/admin-login.html');
        } else {
            window.location.href = resolvePagePath('auth/login.html');
        }
    },
    
    // Update navigation based on auth state
    updateNavigation() {
        const navAuth = document.querySelector('.nav-auth');
        if (!navAuth) return;
        
        if (this.isLoggedIn()) {
            const dashboardUrl = this.getDashboardUrl();
            const profileUrl = this.getProfileUrl();
            
            navAuth.innerHTML = `
                <a href="${dashboardUrl}" class="btn btn-outline">
                    <i class="fas fa-table-columns"></i>
                    Dashboard
                </a>
                <a href="${profileUrl}" class="btn btn-primary">
                    <i class="fas fa-user"></i>
                    My Profile
                </a>
            `;
        }
    },
    
    // Protect route - redirect if not logged in
    requireAuth() {
        if (!this.isLoggedIn()) {
            window.location.href = resolvePagePath('auth/login.html');
            return false;
        }
        return true;
    },
    
    // Protect route - redirect if not specific role
    requireRole(role) {
        if (!this.isLoggedIn()) {
            const loginUrl = role === 'admin'
                ? resolvePagePath('auth/admin-login.html')
                : resolvePagePath('auth/login.html');
            window.location.href = loginUrl;
            return false;
        }
        
        if (!this.hasRole(role)) {
            window.location.href = resolvePagePath('index.html');
            return false;
        }
        
        return true;
    },
    
    // Initialize auth state
    init() {
        // Check token expiry on page load
        const token = this.getToken();
        if (token) {
            try {
                // Decode JWT payload (handle URL-safe base64)
                const base64Url = token.split('.')[1];
                const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
                const padded = base64.padEnd(base64.length + (4 - base64.length % 4) % 4, '=');
                const payload = JSON.parse(atob(padded));
                
                // Check if token is expired
                if (payload.exp && payload.exp * 1000 < Date.now()) {
                    this.logout();
                    return;
                }
                
                // Update navigation
                this.updateNavigation();
                
            } catch (e) {
                console.error('Invalid token:', e);
                this.logout();
            }
        }
    }
};

// Initialize auth on page load
document.addEventListener('DOMContentLoaded', () => {
    Auth.init();
});
