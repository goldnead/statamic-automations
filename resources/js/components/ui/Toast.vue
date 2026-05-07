<template>
    <Teleport to="body">
        <transition name="sa-toast">
            <div v-if="visible" class="sa-toast" :class="`sa-toast--${level}`" role="status">
                <span class="sa-toast__message">{{ message }}</span>
                <button v-if="dismissible" type="button" class="sa-toast__dismiss" @click="hide">×</button>
            </div>
        </transition>
    </Teleport>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';

const props = defineProps({
    message: { type: String, required: true },
    level: { type: String, default: 'info' },
    duration: { type: Number, default: 3500 },
    dismissible: { type: Boolean, default: true },
});

const emit = defineEmits(['hidden']);

const visible = ref(true);
let timer = null;

function hide() {
    visible.value = false;
    emit('hidden');
}

watch(() => props.message, () => {
    visible.value = true;
    schedule();
});

onMounted(schedule);

function schedule() {
    if (timer) clearTimeout(timer);
    if (props.duration > 0) {
        timer = setTimeout(hide, props.duration);
    }
}
</script>
