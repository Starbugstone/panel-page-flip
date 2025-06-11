import React, { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card';
import { Button } from '../components/ui/button';
import { Badge } from '../components/ui/badge';
import { Alert, AlertDescription } from '../components/ui/alert';
import { 
  AlertTriangle, 
  RotateCcw, 
  Clock, 
  GitCommit, 
  User, 
  CheckCircle,
  XCircle,
  RefreshCw
} from 'lucide-react';

const RollbackManagement = () => {
  const [deployments, setDeployments] = useState([]);
  const [rollbackTargets, setRollbackTargets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [rollbackLoading, setRollbackLoading] = useState(false);
  const [error, setError] = useState(null);
  const [success, setSuccess] = useState(null);

  useEffect(() => {
    fetchData();
  }, []);

  const fetchData = async () => {
    try {
      setLoading(true);
      const [deploymentsRes, targetsRes] = await Promise.all([
        fetch('/api/deployment/history', {
          credentials: 'include'
        }),
        fetch('/api/deployment/rollback-targets', {
          credentials: 'include'
        })
      ]);

      if (deploymentsRes.ok) {
        const deploymentsData = await deploymentsRes.json();
        setDeployments(deploymentsData.deployments || []);
      }

      if (targetsRes.ok) {
        const targetsData = await targetsRes.json();
        setRollbackTargets(targetsData.targets || []);
      }
    } catch (err) {
      setError('Failed to fetch deployment data: ' + err.message);
    } finally {
      setLoading(false);
    }
  };

  const handleRollback = async (commitHash = null, reason = '') => {
    if (!window.confirm(
      commitHash 
        ? `Are you sure you want to rollback to commit ${commitHash}?`
        : 'Are you sure you want to rollback to the previous deployment?'
    )) {
      return;
    }

    try {
      setRollbackLoading(true);
      setError(null);
      setSuccess(null);

      const response = await fetch('/api/deployment/rollback', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({
          commit: commitHash,
          reason: reason || 'Manual rollback via admin interface'
        })
      });

      const data = await response.json();

      if (response.ok) {
        setSuccess(`Rollback completed successfully! ${commitHash ? `Rolled back to ${commitHash}` : 'Rolled back to previous deployment'}`);
        fetchData(); // Refresh the data
      } else {
        setError(data.message || 'Rollback failed');
      }
    } catch (err) {
      setError('Rollback failed: ' + err.message);
    } finally {
      setRollbackLoading(false);
    }
  };

  const getStatusBadge = (status) => {
    switch (status) {
      case 'success':
        return <Badge className="bg-green-100 text-green-800"><CheckCircle className="w-3 h-3 mr-1" />Success</Badge>;
      case 'failed':
        return <Badge className="bg-red-100 text-red-800"><XCircle className="w-3 h-3 mr-1" />Failed</Badge>;
      case 'rolled_back':
        return <Badge className="bg-orange-100 text-orange-800"><RotateCcw className="w-3 h-3 mr-1" />Rolled Back</Badge>;
      default:
        return <Badge variant="secondary">{status}</Badge>;
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center p-8">
        <RefreshCw className="w-6 h-6 animate-spin mr-2" />
        Loading deployment data...
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold">Rollback Management</h1>
        <Button onClick={fetchData} variant="outline">
          <RefreshCw className="w-4 h-4 mr-2" />
          Refresh
        </Button>
      </div>

      {error && (
        <Alert className="border-red-200 bg-red-50">
          <AlertTriangle className="h-4 w-4 text-red-600" />
          <AlertDescription className="text-red-800">{error}</AlertDescription>
        </Alert>
      )}

      {success && (
        <Alert className="border-green-200 bg-green-50">
          <CheckCircle className="h-4 w-4 text-green-600" />
          <AlertDescription className="text-green-800">{success}</AlertDescription>
        </Alert>
      )}

      {/* Quick Rollback Actions */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center">
            <RotateCcw className="w-5 h-5 mr-2" />
            Quick Rollback Actions
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex gap-4">
            <Button 
              onClick={() => handleRollback()}
              disabled={rollbackLoading || rollbackTargets.length === 0}
              className="bg-orange-600 hover:bg-orange-700"
            >
              {rollbackLoading ? (
                <RefreshCw className="w-4 h-4 mr-2 animate-spin" />
              ) : (
                <RotateCcw className="w-4 h-4 mr-2" />
              )}
              Rollback to Previous
            </Button>
            {rollbackTargets.length === 0 && (
              <p className="text-sm text-gray-500 flex items-center">
                No rollback targets available
              </p>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Available Rollback Targets */}
      {rollbackTargets.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center">
              <GitCommit className="w-5 h-5 mr-2" />
              Available Rollback Targets
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              {rollbackTargets.map((target) => (
                <div key={target.commit_hash} className="flex items-center justify-between p-3 border rounded-lg">
                  <div className="flex-1">
                    <div className="flex items-center gap-2 mb-1">
                      <code className="bg-gray-100 px-2 py-1 rounded text-sm font-mono">
                        {target.short_commit}
                      </code>
                      <Badge variant="outline">{target.branch}</Badge>
                    </div>
                    <div className="text-sm text-gray-600 flex items-center gap-4">
                      <span className="flex items-center">
                        <Clock className="w-3 h-3 mr-1" />
                        {target.deployed_at}
                      </span>
                      <span className="flex items-center">
                        <User className="w-3 h-3 mr-1" />
                        {target.deployed_by}
                      </span>
                    </div>
                  </div>
                  <Button
                    onClick={() => handleRollback(target.commit_hash)}
                    disabled={rollbackLoading}
                    variant="outline"
                    size="sm"
                  >
                    <RotateCcw className="w-3 h-3 mr-1" />
                    Rollback
                  </Button>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Deployment History */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center">
            <Clock className="w-5 h-5 mr-2" />
            Deployment History
          </CardTitle>
        </CardHeader>
        <CardContent>
          {deployments.length === 0 ? (
            <p className="text-gray-500 text-center py-4">No deployment history available</p>
          ) : (
            <div className="space-y-3">
              {deployments.map((deployment) => (
                <div key={deployment.id} className="flex items-center justify-between p-3 border rounded-lg">
                  <div className="flex-1">
                    <div className="flex items-center gap-2 mb-1">
                      <code className="bg-gray-100 px-2 py-1 rounded text-sm font-mono">
                        {deployment.short_commit}
                      </code>
                      <Badge variant="outline">{deployment.branch}</Badge>
                      {getStatusBadge(deployment.status)}
                    </div>
                    <div className="text-sm text-gray-600 flex items-center gap-4">
                      <span className="flex items-center">
                        <Clock className="w-3 h-3 mr-1" />
                        {deployment.deployed_at}
                      </span>
                      <span className="flex items-center">
                        <User className="w-3 h-3 mr-1" />
                        {deployment.deployed_by}
                      </span>
                      {deployment.duration && (
                        <span>Duration: {deployment.duration}s</span>
                      )}
                    </div>
                    {deployment.rollback_reason && (
                      <div className="text-sm text-orange-600 mt-1">
                        Rollback reason: {deployment.rollback_reason}
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default RollbackManagement; 