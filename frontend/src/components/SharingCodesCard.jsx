import { useState } from "react";
import { Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { HandedOutCodesList } from "@/components/share/HandedOutCodesList";
import { RedeemCodePanel } from "@/components/share/RedeemCodePanel";
import { SharingIdentityPanel } from "@/components/share/SharingIdentityPanel";
import { useHandedOutCodes } from "@/hooks/use-handed-out-codes";
import { useRedeemShareCode } from "@/hooks/use-redeem-share-code";
import { useSharingIdentity } from "@/hooks/use-sharing-identity";
import { SHARING_CODE_COPY } from "@/lib/sharing";

/**
 * Who you are, and every code on your own account.
 *
 * Three things that all answer "how do people reach me, and what have I handed
 * out?": the username and `U-` code that identify you, the field for redeeming
 * a `C-` or `G-` somebody sent, and the list of codes you issued.
 *
 * *Creating* a content code is not here. That belongs to the share workflow,
 * because it starts by choosing comics — but the codes it creates come back
 * here to be watched and withdrawn, so there is one place to look.
 */
export function SharingCodesCard({ onRedeemed, reloadKey = 0 }) {
  const identity = useSharingIdentity();
  const handedOut = useHandedOutCodes(reloadKey);
  const redeeming = useRedeemShareCode(onRedeemed);
  const [confirmingRotation, setConfirmingRotation] = useState(false);

  return (
    <Card className="mb-6">
      <CardContent className="grid gap-6 p-4 md:grid-cols-2">
        <SharingIdentityPanel
          identity={identity.identity}
          loadFailed={identity.loadFailed}
          copied={identity.copied}
          isRotating={identity.isRotating}
          onCopy={identity.copyCode}
          onRotate={() => setConfirmingRotation(true)}
        />

        <RedeemCodePanel
          value={redeeming.value}
          onChange={redeeming.change}
          isRedeeming={redeeming.isRedeeming}
          error={redeeming.error}
          onRedeem={redeeming.redeem}
        />

        <HandedOutCodesList handedOut={handedOut} />
      </CardContent>

      {/* A confirmation rather than a plain button: rotating is one click that
          silently breaks the code in every conversation it was pasted into, and
          that consequence has to be stated before it happens rather than
          explained afterwards. */}
      <Dialog open={confirmingRotation} onOpenChange={setConfirmingRotation}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Replace your user code?</DialogTitle>
            <DialogDescription>{SHARING_CODE_COPY.rotate}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmingRotation(false)} disabled={identity.isRotating}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              disabled={identity.isRotating}
              onClick={async () => {
                if (await identity.rotate()) setConfirmingRotation(false);
              }}
            >
              {identity.isRotating && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Replace my code
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
