<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

type HealthResponse = {
    status: string;
    service: string;
    version: number;
};

const health = ref<HealthResponse | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const apiState = computed(() => {
    if (loading.value) return 'Prüfung läuft';
    if (health.value?.status === 'ok') return 'Online';
    return 'Nicht erreichbar';
});

onMounted(async () => {
    try {
        const response = await axios.get<HealthResponse>('/api/v1/health');
        health.value = response.data;
    } catch (exception) {
        error.value = 'Die API konnte nicht erreicht werden.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section class="dashboard-grid">
        <article class="hero-card">
            <div>
                <span class="eyebrow">FOUNDATION STATUS</span>
                <h2>Die technische Basis für deine Airline-Simulation steht.</h2>
                <p>
                    Dieses Dashboard zeigt ausschließlich bereits implementierte Systemfunktionen.
                    Airline-, Flotten- und Flugmodule werden aktiviert, sobald ihre Backend-Workflows
                    vollständig verfügbar sind.
                </p>
            </div>
            <div class="hero-orbit" aria-hidden="true">
                <div class="orbit-ring"></div>
                <div class="aircraft-glyph">✈</div>
            </div>
        </article>

        <article class="metric-card">
            <span class="metric-label">API</span>
            <strong>{{ apiState }}</strong>
            <span class="metric-detail">/api/v1/health</span>
        </article>

        <article class="metric-card">
            <span class="metric-label">Backend</span>
            <strong>Laravel 13</strong>
            <span class="metric-detail">API-first Foundation</span>
        </article>

        <article class="metric-card">
            <span class="metric-label">Simulation</span>
            <strong>Serverseitig</strong>
            <span class="metric-detail">World-time ready</span>
        </article>

        <article class="panel system-panel">
            <div class="panel-header">
                <div>
                    <span class="eyebrow">SYSTEM CHECK</span>
                    <h3>Backend-Verbindung</h3>
                </div>
                <span :class="['state-badge', health?.status === 'ok' ? 'state-ok' : '']">
                    {{ apiState }}
                </span>
            </div>

            <dl v-if="health" class="system-list">
                <div>
                    <dt>Service</dt>
                    <dd>{{ health.service }}</dd>
                </div>
                <div>
                    <dt>API-Version</dt>
                    <dd>v{{ health.version }}</dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd>{{ health.status }}</dd>
                </div>
            </dl>

            <p v-else-if="error" class="error-message">{{ error }}</p>
            <p v-else class="muted">Verbindung wird geprüft …</p>
        </article>

        <article class="panel foundation-panel">
            <div class="panel-header">
                <div>
                    <span class="eyebrow">PHASE 1</span>
                    <h3>Foundation-Domänen</h3>
                </div>
            </div>
            <div class="domain-chips" aria-label="Bereits modellierte Domänen">
                <span>Accounts</span>
                <span>Worlds</span>
                <span>Airlines</span>
                <span>Airports</span>
                <span>Aircraft</span>
                <span>Routes</span>
                <span>Flights</span>
                <span>Finance Ledger</span>
            </div>
        </article>
    </section>
</template>
