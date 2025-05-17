
import { Comic, User } from "../types";

export const mockUser: User = {
  id: "user-1",
  name: "Comic Fan",
  email: "comicfan@example.com"
};

export const mockComics: Comic[] = [
  {
    id: "comic-1",
    title: "Amazing Adventures",
    cover: "https://images.unsplash.com/photo-1612036782180-6f0822045d55?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1400&q=80",
    description: "Follow the adventures of Captain Awesome as he saves the world from evil.",
    author: "John Creator",
    publisher: "Comic House",
    totalPages: 32,
    lastReadPage: 12,
    lastReadAt: "2023-06-01T12:00:00Z"
  },
  {
    id: "comic-2",
    title: "Spectacular Tales",
    cover: "https://images.unsplash.com/photo-1618519764620-7403abdbdfe9?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1400&q=80",
    description: "An anthology of short stories from various worlds and dimensions.",
    author: "Jane Writer",
    publisher: "Comic House",
    totalPages: 60
  },
  {
    id: "comic-3",
    title: "Dark Nights",
    cover: "https://images.unsplash.com/photo-1601513445506-2ab0d4fb4229?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1400&q=80",
    description: "The city needs a hero. It's about to get something else entirely.",
    author: "Michael Illustrator",
    publisher: "Dark Comics",
    totalPages: 24,
    lastReadPage: 3,
    lastReadAt: "2023-06-10T15:30:00Z"
  },
  {
    id: "comic-4",
    title: "Space Explorers",
    cover: "https://images.unsplash.com/photo-1638613067237-b1127ef06c00?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1400&q=80",
    description: "The final frontier awaits as the crew of the Starship Discovery embarks on their mission.",
    author: "Sci-Fi Studios",
    publisher: "Future Comics",
    totalPages: 48
  },
  {
    id: "comic-5",
    title: "Mystic Academy",
    cover: "https://images.unsplash.com/photo-1604889836770-8aa0afba569d?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1400&q=80",
    description: "Young wizards learn to control their powers in this coming-of-age tale.",
    author: "Magic Pen",
    publisher: "Wonder Comics",
    totalPages: 36,
    lastReadPage: 20,
    lastReadAt: "2023-06-18T09:15:00Z"
  },
  {
    id: "comic-6",
    title: "Robot Revolution",
    cover: "https://images.unsplash.com/photo-1635863138275-d9b33299680b?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1400&q=80",
    description: "When artificial intelligence evolves beyond human control, who will save humanity?",
    author: "Tech Writer",
    publisher: "Future Comics",
    totalPages: 42
  }
];

export const generateComicPages = (comicId: string, totalPages: number = 10): string[] => {
  // In a real app, this would fetch from an API
  // For now, we'll use placeholder images
  return Array(totalPages)
    .fill("")
    .map((_, index) => 
      `https://placehold.co/800x1200/9b87f5/FFFFFF/png?text=Comic+${comicId}+-+Page+${index + 1}`
    );
};
