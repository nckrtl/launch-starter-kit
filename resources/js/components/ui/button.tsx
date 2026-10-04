import { Button as ButtonPrimitive } from "@base-ui/react/button";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

const buttonVariants = cva(
    "group/button inline-flex shrink-0 items-center justify-center font-medium whitespace-nowrap transition-all duration-150 ease-out outline-none select-none active:translate-y-px disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0",
    {
        variants: {
            variant: {
                default: "btn-ds-default",
                outline: "btn-ds-outline",
                secondary: "btn-ds-secondary",
                ghost: "btn-ds-ghost",
                destructive: "btn-ds-destructive",
                link: "btn-ds-link",
            },
            size: {
                default: "h-8 gap-1.5 rounded-[4px] px-2.5 text-sm",
                xs: "h-6 gap-1 rounded-[3px] px-2 text-xs",
                sm: "h-7 gap-1 rounded-[3px] px-2.5 text-[13px]",
                lg: "h-9 gap-1.5 rounded-[4px] px-3.5 text-sm",
                icon: "size-8 rounded-[4px]",
                "icon-xs": "size-6 rounded-[3px]",
                "icon-sm": "size-7 rounded-[3px]",
                "icon-lg": "size-9 rounded-[4px]",
            },
        },
        defaultVariants: {
            variant: "default",
            size: "default",
        },
    },
);

function Button({
    className,
    variant = "default",
    size = "default",
    ...props
}: ButtonPrimitive.Props & VariantProps<typeof buttonVariants>) {
    return (
        <ButtonPrimitive
            data-slot="button"
            className={cn(buttonVariants({ variant, size, className }))}
            {...props}
        />
    );
}

export { Button, buttonVariants };
