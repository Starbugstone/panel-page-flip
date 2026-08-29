import { Search, UserPlus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

/** The list's heading, its search box, and the one thing it can create. */
export function AdminUsersToolbar({ title, description, searchPlaceholder, searchInput, onSearch, onAddUser }) {
  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 className="text-xl font-bold">{title}</h2>
        {description && <p className="text-sm text-muted-foreground">{description}</p>}
      </div>
      <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center sm:gap-4">
        <div className="relative w-full sm:w-auto">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            type="search"
            placeholder={searchPlaceholder}
            className="w-full pl-8 sm:w-[250px]"
            value={searchInput}
            onChange={(event) => onSearch(event.target.value)}
          />
        </div>
        {onAddUser && (
          <Button className="w-full sm:w-auto" onClick={onAddUser}>
            <UserPlus className="mr-2 h-4 w-4" />
            Add User
          </Button>
        )}
      </div>
    </div>
  );
}
