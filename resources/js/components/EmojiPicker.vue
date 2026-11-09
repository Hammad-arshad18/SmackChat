<script setup>
import { computed, ref } from 'vue';
import { emojiCategories, quickReactions } from '@/lib/emojis';

const props = defineProps({
    forReactions: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);

const activeCategory = ref('all');
const query = ref('');

const filteredCategories = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) {
        if (activeCategory.value === 'all') return emojiCategories;
        return emojiCategories.filter((cat) => cat.id === activeCategory.value);
    }
    return emojiCategories
        .map((cat) => ({
            ...cat,
            emojis: cat.emojis.filter((emoji) => emoji.includes(q) || cat.label.toLowerCase().includes(q)),
        }))
        .filter((cat) => cat.emojis.length > 0);
});

function selectEmoji(emoji) {
    emit('select', emoji);
}
</script>

<template>
    <div class="emoji-picker-container" :class="{ 'for-reactions': forReactions }">
        <div v-if="forReactions" class="quick-reactions-row">
            <span class="quick-title">Quick</span>
            <div class="quick-emojis">
                <button
                    v-for="emoji in quickReactions"
                    :key="emoji"
                    type="button"
                    class="emoji-btn quick-btn"
                    @click="selectEmoji(emoji)"
                >
                    {{ emoji }}
                </button>
            </div>
        </div>

        <div class="emoji-filter-bar">
            <input
                v-model="query"
                type="text"
                placeholder="Search emoji…"
                class="emoji-search-input"
            />
            <div class="category-pills">
                <button
                    type="button"
                    class="category-pill"
                    :class="{ active: activeCategory === 'all' }"
                    @click="activeCategory = 'all'"
                >
                    All
                </button>
                <button
                    v-for="cat in emojiCategories"
                    :key="cat.id"
                    type="button"
                    class="category-pill"
                    :class="{ active: activeCategory === cat.id }"
                    @click="activeCategory = cat.id"
                >
                    {{ cat.label }}
                </button>
            </div>
        </div>

        <div class="emoji-scroll-area">
            <div v-for="cat in filteredCategories" :key="cat.id" class="emoji-category-section">
                <div class="category-header">{{ cat.label }}</div>
                <div class="emoji-grid-expanded">
                    <button
                        v-for="emoji in cat.emojis"
                        :key="emoji"
                        type="button"
                        class="emoji-btn"
                        @click="selectEmoji(emoji)"
                    >
                        {{ emoji }}
                    </button>
                </div>
            </div>
            <div v-if="!filteredCategories.length" class="emoji-empty">
                No matching emojis
            </div>
        </div>
    </div>
</template>
