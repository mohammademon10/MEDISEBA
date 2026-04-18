/**
 * MediSeba - API Client
 * 
 * Handles all API communication with the backend
 */

function getAppBasePath() {
    if (typeof window === 'undefined') {
        return '';
    }

    const pathname = window.location.pathname || '';
    const normalizedPath = pathname.replace(/\/{2,}/g, '/');
    const frontendIndex = normalizedPath.indexOf('/frontend/');

    if (frontendIndex !== -1) {
        return normalizedPath.slice(0, frontendIndex);
    }

    const backendIndex = normalizedPath.indexOf('/backend/');

    if (backendIndex !== -1) {
        return normalizedPath.slice(0, backendIndex);
    }

    const segments = normalizedPath.split('/').filter(Boolean);

    if (segments.length === 0) {
        return '';
    }

    const lastSegment = segments[segments.length - 1] || '';

    if (lastSegment.includes('.')) {
        segments.pop();
    }

    // Strip known app subdirectories to reach the true project root
    const knownSubfolders = ['auth', 'patient', 'doctor', 'pages', 'admin'];
    while (segments.length > 0 && knownSubfolders.includes(segments[segments.length - 1])) {
        segments.pop();
    }

    return segments.length ? `/${segments.join('/')}` : '';
}

const APP_BASE_PATH = getAppBasePath();
const API_BASE_URL = `${APP_BASE_PATH}/backend/index.php`.replace(/\/{2,}/g, '/');

function buildQueryString(params = {}) {
    const query = new URLSearchParams();

    Object.entries(params || {}).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') {
            return;
        }

        if (Array.isArray(value)) {
            value.forEach((item) => {
                if (item !== undefined && item !== null && item !== '') {
                    query.append(key, String(item));
                }
            });
            return;
        }

        query.append(key, String(value));
    });

    return query.toString();
}

// API Client
const API = {
    // Generic request method
    async request(endpoint, options = {}) {
        const url = `${API_BASE_URL}/${endpoint}`;
        const isFormData = options.body instanceof FormData;
        
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        };
        
        // Add auth token if available
        const token = Auth.getToken();
        if (token) {
            defaultOptions.headers['Authorization'] = `Bearer ${token}`;
            defaultOptions.headers['X-Authorization'] = `Bearer ${token}`;
            defaultOptions.headers['X-Auth-Token'] = token;
        }
        
        const config = {
            ...defaultOptions,
            ...options,
            headers: {
                ...defaultOptions.headers,
                ...options.headers
            }
        };

        if (isFormData) {
            delete config.headers['Content-Type'];
        }
        
        if (config.body && typeof config.body === 'object' && !isFormData) {
            config.body = JSON.stringify(config.body);
        }
        
        try {
            const response = await fetch(url, config);
            const data = await response.json();
            
            if (!response.ok || !data.success) {
                let message = data.message || 'Request failed';

                if (message === 'Validation failed' && data.errors && typeof data.errors === 'object') {
                    const firstErrorGroup = Object.values(data.errors)[0];
                    if (Array.isArray(firstErrorGroup) && firstErrorGroup.length > 0) {
                        message = firstErrorGroup[0];
                    } else if (typeof firstErrorGroup === 'string' && firstErrorGroup.trim() !== '') {
                        message = firstErrorGroup;
                    }
                }

                throw new Error(message);
            }
            
            return data.data || data;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    },

    async download(endpoint, defaultFilename = 'download.pdf', options = {}) {
        const url = `${API_BASE_URL}/${endpoint}`;
        const defaultOptions = {
            method: 'GET',
            headers: {
                'Accept': 'application/pdf,application/octet-stream'
            }
        };

        const token = Auth.getToken();
        if (token) {
            defaultOptions.headers['Authorization'] = `Bearer ${token}`;
            defaultOptions.headers['X-Authorization'] = `Bearer ${token}`;
            defaultOptions.headers['X-Auth-Token'] = token;
        }

        const config = {
            ...defaultOptions,
            ...options,
            headers: {
                ...defaultOptions.headers,
                ...options.headers
            }
        };

        try {
            const response = await fetch(url, config);
            const contentType = response.headers.get('content-type') || '';

            if (!response.ok || contentType.includes('application/json')) {
                let message = 'Download failed';

                try {
                    const data = await response.json();
                    message = data.message || message;
                } catch (parseError) {
                    console.error('Failed to parse download error response:', parseError);
                }

                throw new Error(message);
            }

            const blob = await response.blob();
            const blobUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            const contentDisposition = response.headers.get('content-disposition') || '';
            const filenameMatch = contentDisposition.match(/filename="?([^"]+)"?/i);
            const filename = filenameMatch?.[1] || defaultFilename;

            link.href = blobUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();

            setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);

            return { filename };
        } catch (error) {
            console.error('Download Error:', error);
            throw error;
        }
    },
    
    // GET request
    get(endpoint) {
        return this.request(endpoint, { method: 'GET' });
    },
    
    // POST request
    post(endpoint, body) {
        return this.request(endpoint, { method: 'POST', body });
    },
    
    // PUT request
    put(endpoint, body) {
        return this.request(endpoint, { method: 'PUT', body });
    },
    
    // PATCH request
    patch(endpoint, body) {
        return this.request(endpoint, { method: 'PATCH', body });
    },
    
    // DELETE request
    delete(endpoint) {
        return this.request(endpoint, { method: 'DELETE' });
    },

    // Upload endpoints
    uploads: {
        profilePhoto(formData) {
            return API.request('api/uploads/profile-photo', {
                method: 'POST',
                body: formData
            });
        }
    },


    // Authentication endpoints
    auth: {
        login(email, password, role = 'patient') {
            return API.post('api/auth/login', { email, password, role });
        },
        
        register(userData) {
            return API.post('api/auth/register', userData);
        },
        
        me() {
            return API.get('api/auth/me');
        },
        
        logout() {
            return API.post('api/auth/logout', {});
        },
        
        refresh() {
            return API.post('api/auth/refresh', {});
        },
        
        completeProfile(profileData) {
            return API.post('api/auth/complete-profile', profileData);
        },
        
        updateProfile(profileData) {
            return API.put('api/auth/profile', profileData);
        }
    },
    
    // Doctor endpoints
    doctors: {
        list(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/doctors?${queryString}`);
        },
        
        featured() {
            return API.get('api/doctors/featured');
        },

        testimonials(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(queryString ? `api/doctors/testimonials?${queryString}` : 'api/doctors/testimonials');
        },

        publicStats() {
            return API.get('api/doctors/public-stats');
        },
        
        specialties() {
            return API.get('api/doctors/specialties');
        },
        
        get(id) {
            return API.get(`api/doctors/${id}`);
        },
        
        schedule(id) {
            return API.get(`api/doctors/${id}/schedule`);
        },
        
        availableDates(id, days = 30) {
            return API.get(`api/doctors/${id}/available-dates?days=${days}`);
        },
        
        statistics() {
            return API.get('api/doctors/statistics');
        },

        profile() {
            return API.get('api/doctors/profile');
        },
        
        updateProfile(profileData) {
            return API.put('api/doctors/profile', profileData);
        },
        
        updateSchedule(scheduleData) {
            return API.put('api/doctors/schedule', scheduleData);
        }
    },
    
    // Appointment endpoints
    appointments: {
        create(appointmentData) {
            return API.post('api/appointments', appointmentData);
        },
        
        get(id) {
            return API.get(`api/appointments/${id}`);
        },
        
        myAppointments(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/appointments/my-appointments?${queryString}`);
        },
        
        doctorAppointments(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/appointments/doctor-appointments?${queryString}`);
        },
        
        today() {
            return API.get('api/appointments/today');
        },
        
        upcoming() {
            return API.get('api/appointments/upcoming');
        },
        
        updateStatus(id, status, additionalData = {}) {
            return API.patch(`api/appointments/${id}/status`, { status, ...additionalData });
        },

        videoRoom(id) {
            return API.get(`api/appointments/${id}/video-room`);
        },
        
        cancel(id, reason) {
            return API.post(`api/appointments/${id}/cancel`, { reason });
        },

        submitReview(id, reviewData) {
            return API.post(`api/appointments/${id}/review`, reviewData);
        },
        
        statistics() {
            return API.get('api/appointments/statistics');
        }
    },
    
    // Prescription endpoints
    prescriptions: {
        create(prescriptionData) {
            return API.post('api/prescriptions', prescriptionData);
        },
        
        get(id) {
            return API.get(`api/prescriptions/${id}`);
        },

        downloadPdf(id) {
            return API.download(`api/prescriptions/${id}/pdf`, `prescription-${id}.pdf`);
        },
        
        myPrescriptions(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/prescriptions/my-prescriptions?${queryString}`);
        },
        
        doctorPrescriptions(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/prescriptions/doctor-prescriptions?${queryString}`);
        },
        
        search(query) {
            return API.get(`api/prescriptions/search?q=${encodeURIComponent(query)}`);
        },
        
        followUps(days = 7) {
            return API.get(`api/prescriptions/follow-ups?days=${days}`);
        },
        
        update(id, prescriptionData) {
            return API.put(`api/prescriptions/${id}`, prescriptionData);
        },
        
        delete(id) {
            return API.delete(`api/prescriptions/${id}`);
        }
    },
    // Payment endpoints
    payments: {
        get(id) {
            return API.get(`api/payments/${id}`);
        },

        downloadReceipt(id) {
            return API.download(`api/payments/${id}/receipt`, `receipt-${id}.pdf`);
        },
        
        myPayments(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/payments/my-payments?${queryString}`);
        },
        
        doctorPayments(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/payments/doctor-payments?${queryString}`);
        },
        
        statistics(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/payments/statistics?${queryString}`);
        },
        
        dailyRevenue(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/payments/daily-revenue?${queryString}`);
        },
        
        initiate(paymentData) {
            return API.post('api/payments/initiate', paymentData);
        },
        
        callback(callbackData) {
            return API.post('api/payments/callback', callbackData);
        },
        
        refund(id, amount, reason) {
            return API.post(`api/payments/${id}/refund`, { amount, reason });
        }
    },

    // Gemini AI endpoints
    gemini: {
        /**
         * Patient: Explain a medical term in plain language
         * @param {string} term - The medical term or diagnosis to explain
         */
        explain(term) {
            return API.post('api/gemini/explain', { term });
        },

        /**
         * Patient: Convert conversational symptom text into a structured clinical list
         * @param {string} rawText - The patient's freeform description of symptoms
         */
        summarizeSymptoms(rawText) {
            return API.post('api/gemini/summarize-symptoms', { raw_text: rawText });
        },

        /**
         * Doctor: Generate a professional clinical diagnostic summary
         * @param {string} symptoms - Observed/reported symptoms
         * @param {string} notes   - Optional physician notes
         */
        clinicalSummary(symptoms, notes = '') {
            return API.post('api/gemini/clinical-summary', { symptoms, notes });
        }
    }
};

// Utility functions
function showLoading() {
    document.getElementById('loadingOverlay')?.classList.add('active');
}

function hideLoading() {
    document.getElementById('loadingOverlay')?.classList.remove('active');
}

function showError(elementId, message) {
    const element = document.getElementById(elementId);
    if (element) {
        element.textContent = message;
        element.classList.remove('hidden');
    }
}

function hideError(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.classList.add('hidden');
    }
}

function showToast(message, type = 'info', duration = 5000) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    const icon = type === 'success' ? 'check-circle' :
                 type === 'error' ? 'exclamation-circle' :
                 type === 'warning' ? 'exclamation-triangle' : 'info-circle';
    
    toast.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <span class="toast-message"></span>
        <button type="button" class="toast-close" onclick="this.parentElement.remove()" aria-label="Dismiss notification">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    // Set message text safely to prevent XSS
    toast.querySelector('.toast-message').textContent = message;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, duration);
}

// Format date
function formatDate(dateString, options = {}) {
    const date = new Date(dateString);
    const defaultOptions = {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    };
    return date.toLocaleDateString('en-US', { ...defaultOptions, ...options });
}

// Format time
function formatTime(timeString) {
    const [hours, minutes] = timeString.split(':');
    const date = new Date();
    date.setHours(parseInt(hours), parseInt(minutes));
    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

// Format currency
function formatCurrency(amount, currency = 'BDT') {
    return new Intl.NumberFormat('en-BD', {
        style: 'currency',
        currency: currency
    }).format(amount);
}

// Debounce function
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}
