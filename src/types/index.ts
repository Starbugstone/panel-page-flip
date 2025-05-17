
export interface Comic {
  id: string;
  title: string;
  cover: string;
  description: string;
  author?: string;
  publisher?: string;
  totalPages: number;
  lastReadPage?: number;
  lastReadAt?: string;
}

export interface User {
  id: string;
  name: string;
  email: string;
}

export interface AuthState {
  isLoggedIn: boolean;
  user: User | null;
}
