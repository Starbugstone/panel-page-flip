import { useCallback, useEffect, useState } from "react";
import { Link2, Unlink } from "lucide-react";
import { api } from "@/lib/api";
import { useToast } from "@/hooks/use-toast";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

const providerName = (provider) => provider === "google" ? "Google" : provider;
const ignoreConnections = () => {};

export function SignInMethodsCard({ onConnectionsChange = ignoreConnections }) {
  const { toast } = useToast();
  const [providers, setProviders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [busyProvider, setBusyProvider] = useState(null);

  const loadConnections = useCallback(async () => {
    try {
      const data = await api.get("/api/auth/oauth/connections");
      const connections = data.providers || [];
      setProviders(connections);
      onConnectionsChange(connections);
    } catch (error) {
      toast({
        title: "Could not load sign-in methods",
        description: error.message,
        variant: "destructive",
      });
    } finally {
      setLoading(false);
    }
  }, [onConnectionsChange, toast]);

  useEffect(() => {
    let active = true;
    api.get("/api/auth/oauth/connections")
      .then((data) => {
        if (!active) return;
        const connections = data.providers || [];
        setProviders(connections);
        onConnectionsChange(connections);
      })
      .catch((error) => {
        if (!active) return;
        toast({
          title: "Could not load sign-in methods",
          description: error.message,
          variant: "destructive",
        });
      })
      .finally(() => { if (active) setLoading(false); });

    return () => { active = false; };
  }, [onConnectionsChange, toast]);

  const connect = (provider) => {
    const redirect = encodeURIComponent("/settings");
    window.location.assign(`/api/auth/oauth/${provider}/start?redirect=${redirect}`);
  };

  const disconnect = async (provider) => {
    setBusyProvider(provider);
    try {
      await api.delete(`/api/auth/oauth/${provider}`);
      toast({ title: `${providerName(provider)} disconnected` });
      await loadConnections();
    } catch (error) {
      toast({
        title: `Could not disconnect ${providerName(provider)}`,
        description: error.message,
        variant: "destructive",
      });
    } finally {
      setBusyProvider(null);
    }
  };

  const visibleProviders = providers.filter((provider) => provider.enabled || provider.connected);

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2"><Link2 className="h-5 w-5" /> Sign-in methods</CardTitle>
        <CardDescription>
          Connect a provider to sign in without sharing provider tokens with Panel Page Flip.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {loading ? (
          <p className="text-sm text-muted-foreground">Loading sign-in methods…</p>
        ) : visibleProviders.length === 0 ? (
          <p className="text-sm text-muted-foreground">No social sign-in providers are configured on this installation.</p>
        ) : visibleProviders.map((provider) => (
          <div key={provider.provider} className="flex flex-col gap-3 rounded-md border p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="font-medium">{providerName(provider.provider)}</p>
              <p className="text-sm text-muted-foreground">
                {provider.connected
                  ? `Connected${provider.email ? ` as ${provider.email}` : ""}`
                  : "Not connected"}
              </p>
              {!provider.enabled && provider.connected && (
                <p className="mt-1 text-xs text-muted-foreground">This provider is currently disabled by the site operator.</p>
              )}
            </div>
            {provider.connected ? (
              <Button
                variant="outline"
                onClick={() => disconnect(provider.provider)}
                disabled={busyProvider === provider.provider}
              >
                <Unlink className="mr-2 h-4 w-4" />
                {busyProvider === provider.provider ? "Disconnecting…" : "Disconnect"}
              </Button>
            ) : (
              <Button variant="outline" onClick={() => connect(provider.provider)} disabled={!provider.enabled}>
                <Link2 className="mr-2 h-4 w-4" /> Connect
              </Button>
            )}
          </div>
        ))}
      </CardContent>
    </Card>
  );
}
