<script setup>
import { computed } from 'vue';

defineOptions({ name: 'MessageBody' });

const props = defineProps({
    node: { type: Object, required: true },
});

const marks = computed(() => props.node.marks || []);
const link = computed(() => {
    const href = marks.value.find((mark) => mark.type === 'link')?.attrs?.href || '';

    return /^(https?:|mailto:)/i.test(href) ? href : null;
});
const classes = computed(() => ({
    'rt-bold': marks.value.some((mark) => mark.type === 'bold'),
    'rt-italic': marks.value.some((mark) => mark.type === 'italic'),
    'rt-strike': marks.value.some((mark) => mark.type === 'strike'),
    'rt-code': marks.value.some((mark) => mark.type === 'code'),
    'rt-under': marks.value.some((mark) => mark.type === 'underline'),
}));
const code = computed(() => (props.node.content || [])
    .map((child) => child.text || '')
    .join(''));
</script>

<template>
    <template v-if="node.type === 'doc'">
        <MessageBody v-for="(child, index) in node.content || []" :key="index" :node="child" />
    </template>
    <p v-else-if="node.type === 'paragraph'">
        <MessageBody v-for="(child, index) in node.content || []" :key="index" :node="child" />
    </p>
    <blockquote v-else-if="node.type === 'blockquote'">
        <MessageBody v-for="(child, index) in node.content || []" :key="index" :node="child" />
    </blockquote>
    <ul v-else-if="node.type === 'bulletList'">
        <MessageBody v-for="(child, index) in node.content || []" :key="index" :node="child" />
    </ul>
    <ol v-else-if="node.type === 'orderedList'">
        <MessageBody v-for="(child, index) in node.content || []" :key="index" :node="child" />
    </ol>
    <li v-else-if="node.type === 'listItem'">
        <MessageBody v-for="(child, index) in node.content || []" :key="index" :node="child" />
    </li>
    <pre v-else-if="node.type === 'codeBlock'"><code>{{ code }}</code></pre>
    <span v-else-if="node.type === 'mention'" class="mention">@{{ node.attrs?.label }}</span>
    <br v-else-if="node.type === 'hardBreak'">
    <a v-else-if="node.type === 'text' && link" :class="classes" :href="link" target="_blank" rel="noreferrer">{{ node.text }}</a>
    <span v-else-if="node.type === 'text'" :class="classes">{{ node.text }}</span>
    <template v-else>
        <MessageBody v-for="(child, index) in node.content || []" :key="index" :node="child" />
    </template>
</template>
