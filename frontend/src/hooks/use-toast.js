import { toast as sonnerToast } from "sonner";

const methods = {
  destructive: sonnerToast.error,
  warning: sonnerToast.warning,
  success: sonnerToast.success,
};

function toast({ title, description, variant }) {
  const notify = methods[variant] || sonnerToast;

  return notify(title, { description });
}

const api = { toast };

function useToast() {
  return api;
}

export { useToast, toast };
