<script lang="ts">
  import { t } from '../lib/i18n';
  import { announce, COPY_FEEDBACK_MS, surfaceAnnouncer } from '../lib/announce';
  import '../styles/buttons.css';

  // The label reads "Copied" for two seconds and the theme's shared status
  // region (else the surface's status node) says so; `status` is the last
  // resort, for a button rendered outside any search surface.
  const surface = surfaceAnnouncer();
  let copied = $state(false);
  let status = $state('');
  let timer: number | undefined;

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
    if (!announce(t('copied'), surface)) status = t('copied');
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
      copied = false;
      status = '';
    }, COPY_FEEDBACK_MS);
  }

  $effect(() => () => window.clearTimeout(timer));
</script>

<button type="button" class="dre-button-secondary" onclick={copy}
  >{copied ? t('copied') : t('copy_link')}</button
>{#if !surface}<span class="dre-copy__status" role="status">{status}</span>{/if}

<style>
  /* The shared secondary button (styles/buttons.css), at the toolbar's size. */
  button {
    padding-inline: var(--space-3, 0.75rem);
  }
  .dre-copy__status {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
  }
</style>
