<script lang="ts">
  import { t } from '../lib/i18n';
  import '../styles/buttons.css';
  let copied = $state(false);
  async function copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(window.location.href);
    } catch {
      const input = document.createElement('textarea');
      input.value = window.location.href;
      document.body.append(input);
      input.select();
      document.execCommand('copy');
      input.remove();
    }
    copied = true;
    window.setTimeout(() => (copied = false), 1800);
  }
</script>

<button type="button" class="dre-button-secondary" onclick={copy}
  >{copied ? t('copied') : t('copy_link')}</button
>

<style>
  /* The shared secondary button (styles/buttons.css), at the toolbar's size. */
  button {
    padding-inline: var(--space-3, 0.75rem);
  }
</style>
