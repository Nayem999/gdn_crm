import './bootstrap';
import TomSelect from 'tom-select';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('tomSelectField', (options = {}) => ({
        instance: null,
        init() {
            this.instance = new TomSelect(this.$refs.select, {
                maxItems: options.multiple ? null : 1,
                placeholder: options.placeholder ?? undefined,
                onChange: () => {
                    this.$refs.select.dispatchEvent(new Event('change', { bubbles: true }));
                },
            });
        },
        destroy() {
            this.instance?.destroy();
        },
    }));
});
