import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

// Svelte 5 (runes auto-detected). vitePreprocess handles the <script lang="ts">
// blocks in components.
export default {
  preprocess: vitePreprocess(),
};
