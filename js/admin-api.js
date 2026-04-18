/**
 * MediSeba - Admin API Client Extension
 * 
 * Extends the existing API object with admin-specific endpoints
 */

// Extend the existing API object with admin namespace
API.admin = {
    // Dashboard
    dashboard: {
        stats() {
            return API.get('api/admin/dashboard/stats');
        },
        activity(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/admin/dashboard/activity?${queryString}`);
        }
    },

    // Users
    users: {
        list(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/admin/users?${queryString}`);
        },
        updateStatus(id, status) {
            return API.patch(`api/admin/users/${id}/status`, { status });
        },
        delete(id) {
            return API.delete(`api/admin/users/${id}`);
        }
    },

    // Doctors
    doctors: {
        list(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/admin/doctors?${queryString}`);
        },
        get(id) {
            return API.get(`api/admin/doctors/${id}`);
        },
        verify(id, action) {
            return API.patch(`api/admin/doctors/${id}/verify`, { action });
        },
        update(id, data) {
            return API.put(`api/admin/doctors/${id}`, data);
        }
    },

    // Patients
    patients: {
        list(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/admin/patients?${queryString}`);
        },
        updateStatus(id, status) {
            return API.patch(`api/admin/patients/${id}/status`, { status });
        }
    },

    // Appointments
    appointments: {
        list(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/admin/appointments?${queryString}`);
        },
        cancel(id, reason) {
            return API.post(`api/admin/appointments/${id}/cancel`, { reason });
        }
    },

    // Payments
    payments: {
        list(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/admin/payments?${queryString}`);
        },
        downloadReceipt(id) {
            return API.download(`api/admin/payments/${id}/receipt`, `receipt-${id}.pdf`);
        }
    },

    // Prescriptions
    prescriptions: {
        list(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/admin/prescriptions?${queryString}`);
        },
        get(id) {
            return API.get(`api/admin/prescriptions/${id}`);
        }
    },

    // Chats
    chats: {
        list(params = {}) {
            const queryString = buildQueryString(params);
            return API.get(`api/admin/chats?${queryString}`);
        },
        messages(appointmentId) {
            return API.get(`api/admin/chats?appointment_id=${appointmentId}`);
        }
    },

    // Settings
    settings: {
        list() {
            return API.get('api/admin/settings');
        },
        update(settings) {
            return API.put('api/admin/settings', { settings });
        }
    }
};
