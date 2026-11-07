import Aura from '@primeuix/themes/aura';
import { definePreset } from '@primeuix/themes';

/**
 * SmackChat's visual language: warm paper, deep ink, evergreen primary and a
 * citrus highlight. The palette is shared by PrimeVue and the hand-written CSS.
 */
export const SmackPreset = definePreset(Aura, {
    semantic: {
        primary: {
            50: '#ecfdf8',
            100: '#cff9ec',
            200: '#9ff0da',
            300: '#63e2c3',
            400: '#2cc9a8',
            500: '#0e9f86',
            600: '#0b7c69',
            700: '#0a6356',
            800: '#0a5046',
            900: '#0a423b',
            950: '#022620',
        },
        borderRadius: {
            none: '0',
            xs: '6px',
            sm: '10px',
            md: '14px',
            lg: '18px',
            xl: '24px',
        },
        colorScheme: {
            light: {
                surface: {
                    0: '#ffffff',
                    50: '#f7f5ef',
                    100: '#f0ede4',
                    200: '#e4dfd2',
                    300: '#d3ccba',
                    400: '#b3aa94',
                    500: '#928875',
                    600: '#746b5b',
                    700: '#5a5348',
                    800: '#3d3933',
                    900: '#25231f',
                    950: '#12110f',
                },
                primary: {
                    color: '{primary.600}',
                    contrastColor: '#ffffff',
                    hoverColor: '{primary.700}',
                    activeColor: '{primary.800}',
                },
                highlight: {
                    background: 'rgba(242, 184, 75, 0.28)',
                    focusBackground: 'rgba(242, 184, 75, 0.42)',
                    color: '#12110f',
                    focusColor: '#12110f',
                },
                content: {
                    background: '#ffffff',
                    hoverBackground: '#f7f5ef',
                    borderColor: '#e4dfd2',
                    color: '#25231f',
                    hoverColor: '#12110f',
                },
                text: {
                    color: '#25231f',
                    hoverColor: '#12110f',
                    mutedColor: '#746b5b',
                    hoverMutedColor: '#5a5348',
                },
                formField: {
                    background: '#ffffff',
                    disabledBackground: '#f0ede4',
                    filledBackground: '#f7f5ef',
                    filledFocusBackground: '#f7f5ef',
                    borderColor: '#dcd6c8',
                    hoverBorderColor: '#b3aa94',
                    focusBorderColor: '{primary.500}',
                    color: '#25231f',
                    disabledColor: '#928875',
                    placeholderColor: '#a49b87',
                    floatLabelColor: '#746b5b',
                    iconColor: '#928875',
                },
            },
            dark: {
                surface: {
                    0: '#ffffff',
                    50: '#f4f2ec',
                    100: '#e6e2d7',
                    200: '#cbc5b7',
                    300: '#aaa291',
                    400: '#847d6d',
                    500: '#625c50',
                    600: '#47433b',
                    700: '#302d28',
                    800: '#1e1c19',
                    900: '#131211',
                    950: '#0a0a09',
                },
                primary: {
                    color: '{primary.400}',
                    contrastColor: '{primary.950}',
                    hoverColor: '{primary.300}',
                    activeColor: '{primary.200}',
                },
                content: {
                    background: '#171a20',
                    hoverBackground: '#1f232b',
                    borderColor: '#2a2f39',
                    color: '#f1efe8',
                    hoverColor: '#ffffff',
                },
                text: {
                    color: '#f1efe8',
                    hoverColor: '#ffffff',
                    mutedColor: '#a8a597',
                    hoverMutedColor: '#c7c3b8',
                },
                formField: {
                    background: '#15181e',
                    disabledBackground: '#1b1e25',
                    filledBackground: '#1b1e25',
                    filledFocusBackground: '#1b1e25',
                    borderColor: '#2d323c',
                    hoverBorderColor: '#434a57',
                    focusBorderColor: '{primary.400}',
                    color: '#f1efe8',
                    disabledColor: '#767268',
                    placeholderColor: '#757164',
                    floatLabelColor: '#a8a597',
                    iconColor: '#a8a597',
                },
            },
        },
    },
    components: {
        button: {
            root: {
                borderRadius: '12px',
                paddingX: '1.05rem',
                paddingY: '0.62rem',
            },
        },
        inputtext: {
            root: {
                borderRadius: '12px',
                paddingX: '0.9rem',
                paddingY: '0.66rem',
            },
        },
        select: {
            root: {
                borderRadius: '12px',
            },
        },
        dialog: {
            root: {
                borderRadius: '22px',
            },
        },
        datatable: {
            root: {
                borderRadius: '18px',
            },
        },
        tag: {
            colorScheme: {
                light: {
                    contrast: {
                        background: '{surface.900}',
                        color: '#ffffff',
                    },
                    secondary: {
                        background: '{surface.200}',
                        color: '{surface.800}',
                    },
                },
            },
        },
        togglebutton: {
            colorScheme: {
                light: {
                    root: {
                        background: '{formField.background}',
                        borderColor: '{formField.borderColor}',
                        color: '{text.color}',
                        hoverBackground: '{content.hoverBackground}',
                        hoverColor: '{text.hoverColor}',
                        checkedBackground: '{primary.600}',
                        checkedColor: '#ffffff',
                        checkedBorderColor: '{primary.600}',
                    },
                    content: {
                        checkedBackground: 'transparent',
                        checkedShadow: 'none',
                    },
                    icon: {
                        color: '{text.mutedColor}',
                        hoverColor: '{text.color}',
                        checkedColor: '#ffffff',
                    },
                },
                dark: {
                    root: {
                        checkedBackground: '{primary.400}',
                        checkedColor: '{primary.950}',
                        checkedBorderColor: '{primary.400}',
                    },
                    content: {
                        checkedBackground: 'transparent',
                    },
                    icon: {
                        checkedColor: '{primary.950}',
                    },
                },
            },
        },
    },
});
