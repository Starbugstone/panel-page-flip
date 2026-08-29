import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

/** One labelled field in the four-column dialog grid the user forms share. */
export function AdminUserFormField({ id, label, ...input }) {
  return (
    <div className="grid grid-cols-4 items-center gap-4">
      <Label htmlFor={id} className="text-right">{label}</Label>
      <Input id={id} className="col-span-3" {...input} />
    </div>
  );
}

/** What a typed password is still missing, said only once there is one to judge. */
export function PasswordPolicyHint({ password, errors }) {
  if (!password || errors.length === 0) return null;

  return (
    <p className="col-span-4 text-sm text-muted-foreground">
      Password must include: {errors.join(", ")}.
    </p>
  );
}
