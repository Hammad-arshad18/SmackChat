import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

export async function ensureCsrf() {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export function errorText(error, fallback = 'Something went wrong.') {
    const data = error?.response?.data;

    if (data?.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first[0]) {
            return first[0];
        }
    }

    return data?.message || error?.message || fallback;
}

export default api;
