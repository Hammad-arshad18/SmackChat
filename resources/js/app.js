import { createApp } from 'vue';
import { createPinia } from 'pinia';
import PrimeVue from 'primevue/config';
import ToastService from 'primevue/toastservice';
import ConfirmationService from 'primevue/confirmationservice';
import Tooltip from 'primevue/tooltip';

import Accordion from 'primevue/accordion';
import AccordionContent from 'primevue/accordioncontent';
import AccordionHeader from 'primevue/accordionheader';
import AccordionPanel from 'primevue/accordionpanel';
import Avatar from 'primevue/avatar';
import AvatarGroup from 'primevue/avatargroup';
import Badge from 'primevue/badge';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Checkbox from 'primevue/checkbox';
import Chip from 'primevue/chip';
import Column from 'primevue/column';
import CommandMenu from 'primevue/commandmenu';
import ConfirmDialog from 'primevue/confirmdialog';
import DataTable from 'primevue/datatable';
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
import Divider from 'primevue/divider';
import Drawer from 'primevue/drawer';
import FloatLabel from 'primevue/floatlabel';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputNumber from 'primevue/inputnumber';
import InputOtp from 'primevue/inputotp';
import InputText from 'primevue/inputtext';
import Knob from 'primevue/knob';
import Menu from 'primevue/menu';
import Message from 'primevue/message';
import MeterGroup from 'primevue/metergroup';
import MultiSelect from 'primevue/multiselect';
import OverlayBadge from 'primevue/overlaybadge';
import Password from 'primevue/password';
import Popover from 'primevue/popover';
import ProgressBar from 'primevue/progressbar';
import ScrollPanel from 'primevue/scrollpanel';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Skeleton from 'primevue/skeleton';
import Tab from 'primevue/tab';
import TabList from 'primevue/tablist';
import TabPanel from 'primevue/tabpanel';
import TabPanels from 'primevue/tabpanels';
import Tabs from 'primevue/tabs';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import Toast from 'primevue/toast';
import ToggleButton from 'primevue/togglebutton';
import ToggleSwitch from 'primevue/toggleswitch';
import Toolbar from 'primevue/toolbar';
import 'primeicons/primeicons.css';

import App from './App.vue';
import router from './router';
import { SmackPreset } from './theme';
import { useUiStore } from './stores/ui';

const pinia = createPinia();
const app = createApp(App);

app.use(pinia);
app.use(router);
app.use(PrimeVue, {
    license: import.meta.env.VITE_PRIMEVUE_LICENSE_KEY || undefined,
    ripple: true,
    theme: {
        preset: SmackPreset,
        options: {
            darkModeSelector: '.app-dark',
            cssLayer: false,
        },
    },
});
app.use(ToastService);
app.use(ConfirmationService);

const components = {
    Accordion,
    AccordionContent,
    AccordionHeader,
    AccordionPanel,
    Avatar,
    AvatarGroup,
    Badge,
    Button,
    Card,
    Checkbox,
    Chip,
    Column,
    CommandMenu,
    ConfirmDialog,
    DataTable,
    DatePicker,
    Dialog,
    Divider,
    Drawer,
    FloatLabel,
    IconField,
    InputIcon,
    InputNumber,
    InputOtp,
    InputText,
    Knob,
    Menu,
    Message,
    MeterGroup,
    MultiSelect,
    OverlayBadge,
    Password,
    Popover,
    ProgressBar,
    ScrollPanel,
    Select,
    SelectButton,
    Skeleton,
    Tab,
    TabList,
    TabPanel,
    TabPanels,
    Tabs,
    Tag,
    Textarea,
    Toast,
    ToggleButton,
    ToggleSwitch,
    Toolbar,
};

Object.entries(components).forEach(([name, component]) => app.component(name, component));
app.directive('tooltip', Tooltip);

const ui = useUiStore(pinia);
ui.applyTheme();
const scheme = window.matchMedia?.('(prefers-color-scheme: dark)');
scheme?.addEventListener?.('change', () => {
    if (ui.theme === 'system') {
        ui.applyTheme();
    }
});

app.mount('#app');

if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
            // Installability is optional — never block the app if SW registration fails.
        });
    });
}
