<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router';
</script>

<template>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-mark">AE</div>
                <div>
                    <strong>Airline Empire</strong>
                    <span>Operations Platform</span>
                </div>
            </div>

            <nav class="nav" aria-label="Hauptnavigation">
                <RouterLink class="nav-link" to="/">
                    <span class="nav-icon">⌂</span>
                    <span class="nav-label">Dashboard</span>
                </RouterLink>
            </nav>

            <div class="sidebar-footer">
                <span class="phase-pill">Phase 1 · Foundation</span>
            </div>
        </aside>

        <main class="workspace">
            <header class="topbar">
                <div>
                    <span class="eyebrow">AIRLINE OPERATIONS</span>
                    <h1>Command Center</h1>
                </div>
                <div class="topbar-meta">
                    <span class="status-dot" aria-hidden="true"></span>
                    Foundation
                </div>
            </header>

            <RouterView />
        </main>
    </div>
</template>
