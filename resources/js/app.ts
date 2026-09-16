import '../css/app.css';

import axios from 'axios';
import { createPinia } from 'pinia';
import { createApp } from 'vue';

import App from './App.vue';
import router from './router';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.withCredentials = true;

const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
if (csrf?.content) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrf.content;
}

createApp(App)
    .use(createPinia())
    .use(router)
    .mount('#app');
